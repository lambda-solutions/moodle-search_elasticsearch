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

        if (empty($limit)) {
            $limit = \core_search\manager::MAX_RESULTS;
        }

        $search = $this->create_user_query($filters, $usercontexts);

        $response = $this->make_request($search);
        //TODO: Respect limit of results

        return $response;
    }

    protected function create_user_query($filters, $usercontexts) {
        global $USER;
                // TODO: filter usercontexts.
        //Add filter for owneruserid: -> \core_search\manager::NO_OWNER_ID or $USER->id

        //WHAT IS IN $filters?!?!

        $data = clone $filters;

        $query = array('query' => array('filtered' => array('query' => array('bool' => array('must' => array(array('match' => array('content' => $data->q))))))));
        //Add title matching to the query so that it affects query score, if there is a title.
        if (!empty($data->title)) {
            $query['query']['filtered']['query']['bool']['must'][] = array('match' => array('title' => $data->title));            
        }

        //Apply filters
        //
        //Filter for owneruserid
        $query['query']['filtered']['query']['bool']['should'][] = array('term' => array('owneruserid' => \core_search\manager::NO_OWNER_ID));
        $query['query']['filtered']['query']['bool']['should'][] = array('term' => array('owneruserid' => $USER->id));

        //Add filter for the proper contextid that the user can access. If $usercontexts is true, the user can view all contexts.
        if ($usercontexts && is_array($usercontexts)) {
            $allcontexts = array();
            foreach ($usercontexts as $areaid => $areacontexts) {
                foreach ($areacontexts as $contextid) {
                    //Ensure contextids are unique.
                    $allcontexts[$contextid] = $contextid;
                }
            }
            if (empty($allcontexts)) {
                //User has no valid contexts so return no results.
                return array(); //CHECK THAT BLANK SEARCH RETURNS NOTHING IN ELASTICSEARCH!!!!
            }

            //Add contextid filters
            foreach ($allcontexts as $cid => $contextid) {
                //TODO: Fix query to filter for contextid
                //$query['query']['bool']['filter']['bool']['must']['should'][] = array('term' => array('contextid' => $contextid)); 
            }            

        }

        //Add filter for modified date ranges
        //TODO: Fix query to filter for these
        if ($data->timestart > 0) {
            $query['query']['filtered']['filter']['bool']['must'][] = array('range' => array('modified' => array('gte' => $data->timestart)));
        }
        if ($data->timeend > 0) {
            $query['query']['filtered']['filter']['bool']['must'][] = array('range' => array('modified' => array('lte' => $data->timeend)));
        }

        return $query;
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
        $url = $this->serverhostname.'/'.$this->indexname.'/_search';

        $c = new \curl();
        $jsonsearch = json_encode($search);

        // A giant block of code that is really just error checking around the curl request.
        try {
            // Now actually do the request.
            $result = $c->post($url, $jsonsearch);
            $code = $c->get_errno();
            $info = $c->get_info();
            // Now error handling. It is just informational, since we aren't tracking per file/doc results.
            if ($code != 0) {
                // This means an internal cURL error occurred error is in result.
                $message = 'Curl error '.$code.' while searching with query: '.$jsonsearch.': '.$result.'. Info: '.$info.'.';
                debugging($message, DEBUG_DEVELOPER);
            }
        } catch (\Exception $e) {
            // There was an error, but we are not tracking per-file success, so we just continue on.
            debugging('Unknown exception while searching with query: "'.$jsonsearch.'".', DEBUG_DEVELOPER);
        }

        $docs = array();
        $results = json_decode($result);

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
                    //TODO: Delete by itemid
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
            if (isset($results->error)) {
                return $results->error;
            }
            return $results->message;
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
