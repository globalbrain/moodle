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
 * This plugin is used to access files on server file system
 *
 * @since 2.0
 * @package    repository_filesystem
 * @copyright  2010 Dongsheng Cai {@link http://dongsheng.org}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
require_once($CFG->dirroot . '/repository/lib.php');
require_once($CFG->libdir . '/filelib.php');

/**
 * repository_filesystem class
 *
 * Create a repository from your local filesystem
 * *NOTE* for security issue, we use a fixed repository path
 * which is %moodledata%/repository
 *
 * @package    repository
 * @copyright  2009 Dongsheng Cai {@link http://dongsheng.org}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class repository_filesystem extends repository {

    /**
     * Constructor
     *
     * @param int $repositoryid repository ID
     * @param int $context context ID
     * @param array $options
     */
    public function __construct($repositoryid, $context = SYSCONTEXTID, $options = array()) {
        global $CFG;
        parent::__construct($repositoryid, $context, $options);
        $root = $CFG->dataroot.'/repository/';
        $subdir = $this->get_option('fs_path');
        $this->root_path = $root . $subdir . '/';
        if (!empty($options['ajax'])) {
            if (!is_dir($this->root_path)) {
                $created = mkdir($this->root_path, $CFG->directorypermissions, true);
                $ret = array();
                $ret['msg'] = get_string('invalidpath', 'repository_filesystem');
                $ret['nosearch'] = true;
                if ($options['ajax'] && !$created) {
                    echo json_encode($ret);
                    exit;
                }
            }
        }
    }
    public function get_listing($path = '', $page = '') {
        global $CFG, $OUTPUT;
        $list = array();
        $list['list'] = array();
        // process breacrumb trail
        $list['path'] = array(
            array('name'=>get_string('root', 'repository_filesystem'), 'path'=>'')
        );
        $trail = '';
        if (!empty($path)) {
            $parts = explode('/', $path);
            if (count($parts) > 1) {
                foreach ($parts as $part) {
                    if (!empty($part)) {
                        $trail .= ('/'.$part);
                        $list['path'][] = array('name'=>$part, 'path'=>$trail);
                    }
                }
            } else {
                $list['path'][] = array('name'=>$path, 'path'=>$path);
            }
            $this->root_path .= ($path.'/');
        }
        $list['manage'] = false;
        $list['dynload'] = true;
        $list['nologin'] = true;
        $list['nosearch'] = true;
        if ($dh = opendir($this->root_path)) {
            while (($file = readdir($dh)) != false) {
                if ( $file != '.' and $file !='..') {
                    if (filetype($this->root_path.$file) == 'file') {
                        $list['list'][] = array(
                            'title' => $file,
                            'source' => $path.'/'.$file,
                            'size' => filesize($this->root_path.$file),
                            'datecreated' => filectime($this->root_path.$file),
                            'datemodified' => filemtime($this->root_path.$file),
                            'thumbnail' => $OUTPUT->pix_url(file_extension_icon($file, 90))->out(false),
                            'icon' => $OUTPUT->pix_url(file_extension_icon($file, 24))->out(false)
                        );
                    } else {
                        if (!empty($path)) {
                            $current_path = $path . '/'. $file;
                        } else {
                            $current_path = $file;
                        }
                        $list['list'][] = array(
                            'title' => $file,
                            'children' => array(),
                            'datecreated' => filectime($this->root_path.$file),
                            'datemodified' => filemtime($this->root_path.$file),
                            'thumbnail' => $OUTPUT->pix_url(file_folder_icon(90))->out(false),
                            'path' => $current_path
                            );
                    }
                }
            }
        }
        $list['list'] = array_filter($list['list'], array($this, 'filter'));
        return $list;
    }
    public function check_login() {
        return true;
    }
    public function print_login() {
        return true;
    }
    public function global_search() {
        return false;
    }

    /**
     * Return file path
     * @return array
     */
    public function get_file($file, $title = '') {
        global $CFG;
        if ($file{0} == '/') {
            $file = $this->root_path.substr($file, 1, strlen($file)-1);
        } else {
            $file = $this->root_path.$file;
        }
        // this is a hack to prevent move_to_file deleteing files
        // in local repository
        $CFG->repository_no_delete = true;
        return array('path'=>$file, 'url'=>'');
    }

    public function logout() {
        return true;
    }

    public static function get_instance_option_names() {
        return array('fs_path');
    }

    public function set_option($options = array()) {
        $options['fs_path'] = clean_param($options['fs_path'], PARAM_PATH);
        $ret = parent::set_option($options);
        return $ret;
    }

    public function instance_config_form($mform) {
        global $CFG, $PAGE;
        if (has_capability('moodle/site:config', get_system_context())) {
            $path = $CFG->dataroot . '/repository/';
            if (!is_dir($path)) {
                mkdir($path, $CFG->directorypermissions, true);
            }
            if ($handle = opendir($path)) {
                $fieldname = get_string('path', 'repository_filesystem');
                $choices = array();
                while (false !== ($file = readdir($handle))) {
                    if (is_dir($path.$file) && $file != '.' && $file!= '..') {
                        $choices[$file] = $file;
                        $fieldname = '';
                    }
                }
                if (empty($choices)) {
                    $mform->addElement('static', '', '', get_string('nosubdir', 'repository_filesystem', $path));
                    $mform->addElement('hidden', 'fs_path', '');
                } else {
                    $mform->addElement('select', 'fs_path', $fieldname, $choices);
                    $mform->addElement('static', null, '',  get_string('information','repository_filesystem', $path));
                }
                closedir($handle);
            }
        } else {
            $mform->addElement('static', null, '',  get_string('nopermissions', 'error', get_string('configplugin', 'repository_filesystem')));
            return false;
        }
    }

    public static function create($type, $userid, $context, $params, $readonly=0) {
        global $PAGE;
        if (has_capability('moodle/site:config', get_system_context())) {
            return parent::create($type, $userid, $context, $params, $readonly);
        } else {
            require_capability('moodle/site:config', get_system_context());
            return false;
        }
    }
    public static function instance_form_validation($mform, $data, $errors) {
        if (empty($data['fs_path'])) {
            $errors['fs_path'] = get_string('invalidadminsettingname', 'error', 'fs_path');
        }
        return $errors;
    }

    /**
     * User cannot use the external link to dropbox
     *
     * @return int
     */
    public function supported_returntypes() {
        return FILE_INTERNAL | FILE_REFERENCE;
    }

    /**
     * Get file from external repository by reference
     * {@link repository::get_file_reference()}
     * {@link repository::get_file()}
     *
     * @param stdClass $reference file reference db record
     * @return stdClass|null|false
     */
    public function get_file_by_reference($reference) {
        $ref = $reference->reference;
        if ($ref{0} == '/') {
            $filepath = $this->root_path.substr($ref, 1, strlen($ref)-1);
        } else {
            $filepath = $this->root_path.$ref;
        }
        $fileinfo = new stdClass;
        $fileinfo->filepath = $filepath;
        return $fileinfo;
    }

    /**
     * Repository method to serve file
     *
     * @param stored_file $storedfile
     * @param int $lifetime Number of seconds before the file should expire from caches (default 24 hours)
     * @param int $filter 0 (default)=no filtering, 1=all files, 2=html files only
     * @param bool $forcedownload If true (default false), forces download of file rather than view in browser/plugin
     * @param array $options additional options affecting the file serving
     */
    public function send_file($storedfile, $lifetime=86400 , $filter=0, $forcedownload=false, array $options = null) {
        $reference = $storedfile->get_reference();
        if ($reference{0} == '/') {
            $file = $this->root_path.substr($reference, 1, strlen($reference)-1);
        } else {
            $file = $this->root_path.$reference;
        }
        send_file($file, $storedfile->get_filename(), 'default' , $filter, false, $forcedownload);
    }
}
