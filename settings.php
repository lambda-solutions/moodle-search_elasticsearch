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
 * Search engine Elasticsearch plugin settings.
 *
 * @package    search_elasticsearch
 * @copyright  2015 Daniel Neis
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

if ($ADMIN->fulltree) {

    $settings->add(new admin_setting_heading('search_elasticsearch_settings', '',
                                             get_string('pluginname_desc', 'search_elasticsearch')));

    if (!during_initial_install()) {
        $settings->add(new admin_setting_configtext('search_elasticsearch/server_hostname',
                                                    new lang_string('serverhostname', 'search_elasticsearch'),
                                                    new lang_string('serverhostname_desc', 'search_elasticsearch'),
                                                    'localhost:9200', PARAM_TEXT));
        $settings->add(new admin_setting_configtext('search_elasticsearch/index_name',
                                                    new lang_string('indexname', 'search_elasticsearch'),
                                                    new lang_string('indexname_desc', 'search_elasticsearch'),
                                                    'moodle', PARAM_TEXT));
    }
}
