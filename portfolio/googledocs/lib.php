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
 * Google Documents Portfolio Plugin
 *
 * @author Dan Poltawski <talktodan@gmail.com>
 * @license http://www.gnu.org/copyleft/gpl.html GNU Public License
 */
require_once($CFG->libdir.'/portfolio/plugin.php');
require_once($CFG->libdir.'/googleapi.php');

class portfolio_plugin_googledocs extends portfolio_plugin_push_base {
    private $googleoauth = null;

    public function supported_formats() {
        return array(PORTFOLIO_FORMAT_FILE);
    }

    public static function get_name() {
        return get_string('pluginname', 'portfolio_googledocs');
    }

    public function prepare_package() {
        // We send the files as they are, no prep required.
        return true;
    }

    public function get_interactive_continue_url() {
        return 'http://docs.google.com/';
    }

    public function expected_time($callertime) {
        // We trust what the portfolio says.
        return $callertime;
    }

    public function send_package() {
        if (!$this->googleoauth) {
            throw new portfolio_plugin_exception('noauthtoken', 'portfolio_googledocs');
        }

        $gdocs = new google_docs($this->googleoauth);
        foreach ($this->exporter->get_tempfiles() as $file) {
            if (!$gdocs->send_file($file)) {
                throw new portfolio_plugin_exception('sendfailed', 'portfolio_gdocs', $file->get_filename());
            }
        }
    }

    public function steal_control($stage) {
        global $CFG;
        if ($stage != PORTFOLIO_STAGE_CONFIG) {
            return false;
        }

        $this->initialize_oauth();
        if ($this->googleoauth->is_logged_in()) {
            return false;
        } else {
            return $this->googleoauth->get_login_url();
        }
    }

    public function post_control($stage, $params) {
        if ($stage != PORTFOLIO_STAGE_CONFIG) {
            return;
        }

        $this->initialize_oauth();
        if ($this->googleoauth->is_logged_in()) {
            return false;
        } else {
            return $this->googleoauth->get_login_url();
        }
    }

    public static function allows_multiple_instances() {
        return false;
    }

    public static function has_admin_config() {
        return true;
    }

    public static function get_allowed_config() {
        return array('clientid', 'secret');
    }

    public function admin_config_form(&$mform) {
        $a = new stdClass;
        $a->docsurl = get_docs_url('Google_OAuth2_Setup');
        $a->callbackurl = google_oauth::callback_url()->out(false);

        $mform->addElement('static', null, '', get_string('oauthinfo', 'portfolio_googledocs', $a));

        $mform->addElement('text', 'clientid', get_string('clientid', 'portfolio_googledocs'));
        $mform->addElement('text', 'secret', get_string('secret', 'portfolio_googledocs'));

        $strrequired = get_string('required');
        $mform->addRule('clientid', $strrequired, 'required', null, 'client');
        $mform->addRule('secret', $strrequired, 'required', null, 'client');
    }

    private function initialize_oauth() {
        $returnurl = new moodle_url('/portfolio/add.php');
        $returnurl->param('postcontrol', 1);
        $returnurl->param('id', $this->exporter->get('id'));
        $returnurl->param('sesskey', sesskey());

        $clientid = $this->get_config('clientid');
        $secret = $this->get_config('secret');

        $this->googleoauth = new google_oauth($clientid, $secret, $returnurl->out(false), google_docs::REALM);
    }

    public function instance_sanity_check() {
        $clientid = $this->get_config('clientid');
        $secret = $this->get_config('secret');

        // If there is no oauth config (e.g. plugins upgraded from < 2.3 then
        // there will be no config and this plugin should be disabled.
        if (empty($clientid) or empty($secret)) {
            return 'nooauthcredentials';
        }
        return 0;
    }
}
