<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * Elasticsearch engine.
 *
 * @package search_elasticsearch
 * @copyright 2015 Daniel Neis Araujo
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace search_elasticsearch;

defined('MOODLE_INTERNAL') || die();

class engine extends \core_search\engine {

    private $serverhostname = '';
    private $indexname = '';

    public function __construct() {
        $this->serverhostname = get_config('search_elasticsearch', 'server_hostname');
        $this->indexname = get_config('search_elasticsearch', 'index_name');
    }

    public function is_installed() {
        // Elastic Search only needs curl, and Moodle already requires it, so it is ok to just return true.
        return true;
    }

    public function is_server_ready() {
        global $CFG;
        require_once($CFG->dirroot.'/lib/filelib.php');
        $c = new \curl();
        return (bool)json_decode($c->get($this->serverhostname));
    }

    public function add_document($document, $fileindexing = false) {
        $doc = $document->export_for_engine();
        $url = $this->serverhostname.'/'.$this->indexname.'/'.$doc['itemid'];

        $jsondoc = json_encode($doc);

        $c = new \curl();

        // A giant block of code that is really just error checking around the curl request.
        try {
            // Now actually do the request.
            $result = $c->post($url, $jsondoc);
            $code = $c->get_errno();
            $info = $c->get_info();
            // Now error handling. It is just informational, since we aren't tracking per file/doc results.
            if ($code != 0) {
                // This means an internal cURL error occurred error is in result.
                $message = 'Curl error '.$code.' while indexing file with document id '.$doc['itemid'].': '.$result.'.';
                debugging($message, DEBUG_DEVELOPER);
            }
        } catch (\Exception $e) {
            // There was an error, but we are not tracking per-file success, so we just continue on.
            debugging('Unknown exception while indexing file "'.$doc['title'].'".', DEBUG_DEVELOPER);
        }
    }

    public function commit() {
    }

    public function optimize() {
    }

    public function post_file() {
    }

    public function execute_query($filters, $usercontexts, $limit = 0) {

        // TODO: filter usercontexts.
        $search = array('query' => array('bool' => array('must' => array(array('match' => array('content' => $filters->q))))));

        return $this->make_request($search);
    }

    public function get_query_total_count() {
        $url = $this->serverhostname.'/'.$this->indexname.'/_count';

        $c = new \curl();
        $result = json_decode($c->post($url));

        if (isset($result->count)) {
            return $result->count;
        }
        else {
            if (!$result) {
                return false;
            }
            return $result->error;
        }
    }

    /**
     *
     */
    private function make_request($search) {
        $url = $this->serverhostname.'/'.$this->indexname.'/_search?pretty';

        $c = new \curl();
        $results = json_decode($c->post($url, json_encode($search)));
        $docs = array();
        if (isset($results->hits)) {
            $numgranted = 0;
            // TODO: apply \core_search\manager::MAX_RESULTS .
            foreach ($results->hits->hits as $r) {
                if (!$searcharea = $this->get_search_area($r->_source->areaid)) {
                    continue;
                }
                $access = $searcharea->check_access($r->_source->itemid);
                switch ($access) {
                    case \core_search\manager::ACCESS_DELETED:
                    case \core_search\manager::ACCESS_DENIED:
                      continue;
                    case \core_search\manager::ACCESS_GRANTED:
                        $numgranted++;
                        $docs[] = $this->to_document($searcharea, (array)$r->_source);
                        break;
                }
            }
        } else {
            if (!$results) {
                return false;
            }
            return $results->error;
        }
        return $docs;
    }

    public function get_more_like_this_text($text) {

        $search = array('query' => array('more_like_this' => array('fields' => array('content'), 'like_text' => $text,
                                                                   'min_term_freq' => 1, 'max_query_terms' => 12)));
        return $this->make_request($search);
    }

    public function delete($module = null) {
        if (!$module) {
            $url = $this->serverhostname.'/'.$this->indexname.'/?pretty';
            $c = new \curl();
            if ($response = json_decode($c->delete($url))) {
                if ( (isset($response->acknowledged) && ($response->acknowledged == true)) ||
                     ($response->status == 404)) {
                    return true;
                } else {
                    return false;
                }
            } else {
                return false;
            }
        }
        // TODO: handle module.
    }
}
