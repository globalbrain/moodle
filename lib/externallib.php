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
 * Support for external API
 *
 * @package    core_webservice
 * @copyright  2009 Petr Skodak
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

/**
 * Returns detailed function information
 *
 * @param string|object $function name of external function or record from external_function
 * @param int $strictness IGNORE_MISSING means compatible mode, false returned if record not found, debug message if more found;
 *                        MUST_EXIST means throw exception if no record or multiple records found
 * @return stdClass description or false if not found or exception thrown
 * @since Moodle 2.0
 */
function external_function_info($function, $strictness=MUST_EXIST) {
    global $DB, $CFG;

    if (!is_object($function)) {
        if (!$function = $DB->get_record('external_functions', array('name'=>$function), '*', $strictness)) {
            return false;
        }
    }

    //first find and include the ext implementation class
    $function->classpath = empty($function->classpath) ? get_component_directory($function->component).'/externallib.php' : $CFG->dirroot.'/'.$function->classpath;
    if (!file_exists($function->classpath)) {
        throw new coding_exception('Can not find file with external function implementation');
    }
    require_once($function->classpath);

    $function->parameters_method = $function->methodname.'_parameters';
    $function->returns_method    = $function->methodname.'_returns';

    // make sure the implementaion class is ok
    if (!method_exists($function->classname, $function->methodname)) {
        throw new coding_exception('Missing implementation method of '.$function->classname.'::'.$function->methodname);
    }
    if (!method_exists($function->classname, $function->parameters_method)) {
        throw new coding_exception('Missing parameters description');
    }
    if (!method_exists($function->classname, $function->returns_method)) {
        throw new coding_exception('Missing returned values description');
    }

    // fetch the parameters description
    $function->parameters_desc = call_user_func(array($function->classname, $function->parameters_method));
    if (!($function->parameters_desc instanceof external_function_parameters)) {
        throw new coding_exception('Invalid parameters description');
    }

    // fetch the return values description
    $function->returns_desc = call_user_func(array($function->classname, $function->returns_method));
    // null means void result or result is ignored
    if (!is_null($function->returns_desc) and !($function->returns_desc instanceof external_description)) {
        throw new coding_exception('Invalid return description');
    }

    //now get the function description
    //TODO MDL-31115 use localised lang pack descriptions, it would be nice to have
    //      easy to understand descriptions in admin UI,
    //      on the other hand this is still a bit in a flux and we need to find some new naming
    //      conventions for these descriptions in lang packs
    $function->description = null;
    $servicesfile = get_component_directory($function->component).'/db/services.php';
    if (file_exists($servicesfile)) {
        $functions = null;
        include($servicesfile);
        if (isset($functions[$function->name]['description'])) {
            $function->description = $functions[$function->name]['description'];
        }
        if (isset($functions[$function->name]['testclientpath'])) {
            $function->testclientpath = $functions[$function->name]['testclientpath'];
        }
    }

    return $function;
}

/**
 * Exception indicating user is not allowed to use external function in the current context.
 *
 * @package    core_webservice
 * @copyright  2009 Petr Skodak
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @since Moodle 2.0
 */
class restricted_context_exception extends moodle_exception {
    /**
     * Constructor
     *
     * @since Moodle 2.0
     */
    function __construct() {
        parent::__construct('restrictedcontextexception', 'error');
    }
}

/**
 * Base class for external api methods.
 *
 * @package    core_webservice
 * @copyright  2009 Petr Skodak
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @since Moodle 2.0
 */
class external_api {

    /** @var stdClass context where the function calls will be restricted */
    private static $contextrestriction;

    /**
     * Set context restriction for all following subsequent function calls.
     *
     * @param stdClass $context the context restriction
     * @since Moodle 2.0
     */
    public static function set_context_restriction($context) {
        self::$contextrestriction = $context;
    }

    /**
     * This method has to be called before every operation
     * that takes a longer time to finish!
     *
     * @param int $seconds max expected time the next operation needs
     * @since Moodle 2.0
     */
    public static function set_timeout($seconds=360) {
        $seconds = ($seconds < 300) ? 300 : $seconds;
        set_time_limit($seconds);
    }

    /**
     * Validates submitted function parameters, if anything is incorrect
     * invalid_parameter_exception is thrown.
     * This is a simple recursive method which is intended to be called from
     * each implementation method of external API.
     *
     * @param external_description $description description of parameters
     * @param mixed $params the actual parameters
     * @return mixed params with added defaults for optional items, invalid_parameters_exception thrown if any problem found
     * @since Moodle 2.0
     */
    public static function validate_parameters(external_description $description, $params) {
        if ($description instanceof external_value) {
            if (is_array($params) or is_object($params)) {
                throw new invalid_parameter_exception('Scalar type expected, array or object received.');
            }

            if ($description->type == PARAM_BOOL) {
                // special case for PARAM_BOOL - we want true/false instead of the usual 1/0 - we can not be too strict here ;-)
                if (is_bool($params) or $params === 0 or $params === 1 or $params === '0' or $params === '1') {
                    return (bool)$params;
                }
            }
            $debuginfo = 'Invalid external api parameter: the value is "' . $params .
                    '", the server was expecting "' . $description->type . '" type';
            return validate_param($params, $description->type, $description->allownull, $debuginfo);

        } else if ($description instanceof external_single_structure) {
            if (!is_array($params)) {
                throw new invalid_parameter_exception('Only arrays accepted. The bad value is: \''
                        . print_r($params, true) . '\'');
            }
            $result = array();
            foreach ($description->keys as $key=>$subdesc) {
                if (!array_key_exists($key, $params)) {
                    if ($subdesc->required == VALUE_REQUIRED) {
                        throw new invalid_parameter_exception('Missing required key in single structure: '. $key);
                    }
                    if ($subdesc->required == VALUE_DEFAULT) {
                        try {
                            $result[$key] = self::validate_parameters($subdesc, $subdesc->default);
                        } catch (invalid_parameter_exception $e) {
                            //we are only interested by exceptions returned by validate_param() and validate_parameters()
                            //(in order to build the path to the faulty attribut)
                            throw new invalid_parameter_exception($key." => ".$e->getMessage() . ': ' .$e->debuginfo);
                        }
                    }
                } else {
                    try {
                        $result[$key] = self::validate_parameters($subdesc, $params[$key]);
                    } catch (invalid_parameter_exception $e) {
                        //we are only interested by exceptions returned by validate_param() and validate_parameters()
                        //(in order to build the path to the faulty attribut)
                        throw new invalid_parameter_exception($key." => ".$e->getMessage() . ': ' .$e->debuginfo);
                    }
                }
                unset($params[$key]);
            }
            if (!empty($params)) {
                throw new invalid_parameter_exception('Unexpected keys (' . implode(', ', array_keys($params)) . ') detected in parameter array.');
            }
            return $result;

        } else if ($description instanceof external_multiple_structure) {
            if (!is_array($params)) {
                throw new invalid_parameter_exception('Only arrays accepted. The bad value is: \''
                        . print_r($params, true) . '\'');
            }
            $result = array();
            foreach ($params as $param) {
                $result[] = self::validate_parameters($description->content, $param);
            }
            return $result;

        } else {
            throw new invalid_parameter_exception('Invalid external api description');
        }
    }

    /**
     * Clean response
     * If a response attribute is unknown from the description, we just ignore the attribute.
     * If a response attribute is incorrect, invalid_response_exception is thrown.
     * Note: this function is similar to validate parameters, however it is distinct because
     * parameters validation must be distinct from cleaning return values.
     *
     * @param external_description $description description of the return values
     * @param mixed $response the actual response
     * @return mixed response with added defaults for optional items, invalid_response_exception thrown if any problem found
     * @author 2010 Jerome Mouneyrac
     * @since Moodle 2.0
     */
    public static function clean_returnvalue(external_description $description, $response) {
        if ($description instanceof external_value) {
            if (is_array($response) or is_object($response)) {
                throw new invalid_response_exception('Scalar type expected, array or object received.');
            }

            if ($description->type == PARAM_BOOL) {
                // special case for PARAM_BOOL - we want true/false instead of the usual 1/0 - we can not be too strict here ;-)
                if (is_bool($response) or $response === 0 or $response === 1 or $response === '0' or $response === '1') {
                    return (bool)$response;
                }
            }
            $debuginfo = 'Invalid external api response: the value is "' . $response .
                    '", the server was expecting "' . $description->type . '" type';
            try {
                return validate_param($response, $description->type, $description->allownull, $debuginfo);
            } catch (invalid_parameter_exception $e) {
                //proper exception name, to be recursively catched to build the path to the faulty attribut
                throw new invalid_response_exception($e->debuginfo);
            }

        } else if ($description instanceof external_single_structure) {
            if (!is_array($response)) {
                throw new invalid_response_exception('Only arrays accepted. The bad value is: \'' .
                        print_r($response, true) . '\'');
            }
            $result = array();
            foreach ($description->keys as $key=>$subdesc) {
                if (!array_key_exists($key, $response)) {
                    if ($subdesc->required == VALUE_REQUIRED) {
                        throw new invalid_response_exception('Error in response - Missing following required key in a single structure: ' . $key);
                    }
                    if ($subdesc instanceof external_value) {
                        if ($subdesc->required == VALUE_DEFAULT) {
                            try {
                                    $result[$key] = self::clean_returnvalue($subdesc, $subdesc->default);
                            } catch (invalid_response_exception $e) {
                                //build the path to the faulty attribut
                                throw new invalid_response_exception($key." => ".$e->getMessage() . ': ' . $e->debuginfo);
                            }
                        }
                    }
                } else {
                    try {
                        $result[$key] = self::clean_returnvalue($subdesc, $response[$key]);
                    } catch (invalid_response_exception $e) {
                        //build the path to the faulty attribut
                        throw new invalid_response_exception($key." => ".$e->getMessage() . ': ' . $e->debuginfo);
                    }
                }
                unset($response[$key]);
            }

            return $result;

        } else if ($description instanceof external_multiple_structure) {
            if (!is_array($response)) {
                throw new invalid_response_exception('Only arrays accepted. The bad value is: \'' .
                        print_r($response, true) . '\'');
            }
            $result = array();
            foreach ($response as $param) {
                $result[] = self::clean_returnvalue($description->content, $param);
            }
            return $result;

        } else {
            throw new invalid_response_exception('Invalid external api response description');
        }
    }

    /**
     * Makes sure user may execute functions in this context.
     *
     * @param stdClass $context
     * @since Moodle 2.0
     */
    protected static function validate_context($context) {
        global $CFG;

        if (empty($context)) {
            throw new invalid_parameter_exception('Context does not exist');
        }
        if (empty(self::$contextrestriction)) {
            self::$contextrestriction = get_context_instance(CONTEXT_SYSTEM);
        }
        $rcontext = self::$contextrestriction;

        if ($rcontext->contextlevel == $context->contextlevel) {
            if ($rcontext->id != $context->id) {
                throw new restricted_context_exception();
            }
        } else if ($rcontext->contextlevel > $context->contextlevel) {
            throw new restricted_context_exception();
        } else {
            $parents = get_parent_contexts($context);
            if (!in_array($rcontext->id, $parents)) {
                throw new restricted_context_exception();
            }
        }

        if ($context->contextlevel >= CONTEXT_COURSE) {
            list($context, $course, $cm) = get_context_info_array($context->id);
            require_login($course, false, $cm, false, true);
        }
    }
}

/**
 * Common ancestor of all parameter description classes
 *
 * @package    core_webservice
 * @copyright  2009 Petr Skodak
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @since Moodle 2.0
 */
abstract class external_description {
    /** @var string Description of element */
    public $desc;

    /** @var bool Element value required, null not allowed */
    public $required;

    /** @var mixed Default value */
    public $default;

    /**
     * Contructor
     *
     * @param string $desc
     * @param bool $required
     * @param mixed $default
     * @since Moodle 2.0
     */
    public function __construct($desc, $required, $default) {
        $this->desc = $desc;
        $this->required = $required;
        $this->default = $default;
    }
}

/**
 * Scalar value description class
 *
 * @package    core_webservice
 * @copyright  2009 Petr Skodak
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @since Moodle 2.0
 */
class external_value extends external_description {

    /** @var mixed Value type PARAM_XX */
    public $type;

    /** @var bool Allow null values */
    public $allownull;

    /**
     * Constructor
     *
     * @param mixed $type
     * @param string $desc
     * @param bool $required
     * @param mixed $default
     * @param bool $allownull
     * @since Moodle 2.0
     */
    public function __construct($type, $desc='', $required=VALUE_REQUIRED,
            $default=null, $allownull=NULL_ALLOWED) {
        parent::__construct($desc, $required, $default);
        $this->type      = $type;
        $this->allownull = $allownull;
    }
}

/**
 * Associative array description class
 *
 * @package    core_webservice
 * @copyright  2009 Petr Skodak
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @since Moodle 2.0
 */
class external_single_structure extends external_description {

     /** @var array Description of array keys key=>external_description */
    public $keys;

    /**
     * Constructor
     *
     * @param array $keys
     * @param string $desc
     * @param bool $required
     * @param array $default
     * @since Moodle 2.0
     */
    public function __construct(array $keys, $desc='',
            $required=VALUE_REQUIRED, $default=null) {
        parent::__construct($desc, $required, $default);
        $this->keys = $keys;
    }
}

/**
 * Bulk array description class.
 *
 * @package    core_webservice
 * @copyright  2009 Petr Skodak
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @since Moodle 2.0
 */
class external_multiple_structure extends external_description {

     /** @var external_description content */
    public $content;

    /**
     * Constructor
     *
     * @param external_description $content
     * @param string $desc
     * @param bool $required
     * @param array $default
     * @since Moodle 2.0
     */
    public function __construct(external_description $content, $desc='',
            $required=VALUE_REQUIRED, $default=null) {
        parent::__construct($desc, $required, $default);
        $this->content = $content;
    }
}

/**
 * Description of top level - PHP function parameters.
 *
 * @package    core_webservice
 * @copyright  2009 Petr Skodak
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @since Moodle 2.0
 */
class external_function_parameters extends external_single_structure {
}

/**
 * Generate a token
 *
 * @param string $tokentype EXTERNAL_TOKEN_EMBEDDED|EXTERNAL_TOKEN_PERMANENT
 * @param stdClass|int $serviceorid service linked to the token
 * @param int $userid user linked to the token
 * @param stdClass|int $contextorid
 * @param int $validuntil date when the token expired
 * @param string $iprestriction allowed ip - if 0 or empty then all ips are allowed
 * @return string generated token
 * @author  2010 Jamie Pratt
 * @since Moodle 2.0
 */
function external_generate_token($tokentype, $serviceorid, $userid, $contextorid, $validuntil=0, $iprestriction=''){
    global $DB, $USER;
    // make sure the token doesn't exist (even if it should be almost impossible with the random generation)
    $numtries = 0;
    do {
        $numtries ++;
        $generatedtoken = md5(uniqid(rand(),1));
        if ($numtries > 5){
            throw new moodle_exception('tokengenerationfailed');
        }
    } while ($DB->record_exists('external_tokens', array('token'=>$generatedtoken)));
    $newtoken = new stdClass();
    $newtoken->token = $generatedtoken;
    if (!is_object($serviceorid)){
        $service = $DB->get_record('external_services', array('id' => $serviceorid));
    } else {
        $service = $serviceorid;
    }
    if (!is_object($contextorid)){
        $context = get_context_instance_by_id($contextorid, MUST_EXIST);
    } else {
        $context = $contextorid;
    }
    if (empty($service->requiredcapability) || has_capability($service->requiredcapability, $context, $userid)) {
        $newtoken->externalserviceid = $service->id;
    } else {
        throw new moodle_exception('nocapabilitytousethisservice');
    }
    $newtoken->tokentype = $tokentype;
    $newtoken->userid = $userid;
    if ($tokentype == EXTERNAL_TOKEN_EMBEDDED){
        $newtoken->sid = session_id();
    }

    $newtoken->contextid = $context->id;
    $newtoken->creatorid = $USER->id;
    $newtoken->timecreated = time();
    $newtoken->validuntil = $validuntil;
    if (!empty($iprestriction)) {
        $newtoken->iprestriction = $iprestriction;
    }
    $DB->insert_record('external_tokens', $newtoken);
    return $newtoken->token;
}

/**
 * Create and return a session linked token. Token to be used for html embedded client apps that want to communicate
 * with the Moodle server through web services. The token is linked to the current session for the current page request.
 * It is expected this will be called in the script generating the html page that is embedding the client app and that the
 * returned token will be somehow passed into the client app being embedded in the page.
 *
 * @param string $servicename name of the web service. Service name as defined in db/services.php
 * @param int $context context within which the web service can operate.
 * @return int returns token id.
 * @since Moodle 2.0
 */
function external_create_service_token($servicename, $context){
    global $USER, $DB;
    $service = $DB->get_record('external_services', array('name'=>$servicename), '*', MUST_EXIST);
    return external_generate_token(EXTERNAL_TOKEN_EMBEDDED, $service, $USER->id, $context, 0);
}

/**
 * Delete all pre-built services (+ related tokens) and external functions information defined in the specified component.
 *
 * @param string $component name of component (moodle, mod_assignment, etc.)
 */
function external_delete_descriptions($component) {
    global $DB;

    $params = array($component);

    $DB->delete_records_select('external_tokens',
            "externalserviceid IN (SELECT id FROM {external_services} WHERE component = ?)", $params);
    $DB->delete_records_select('external_services_users',
            "externalserviceid IN (SELECT id FROM {external_services} WHERE component = ?)", $params);
    $DB->delete_records_select('external_services_functions',
            "functionname IN (SELECT name FROM {external_functions} WHERE component = ?)", $params);
    $DB->delete_records('external_services', array('component'=>$component));
    $DB->delete_records('external_functions', array('component'=>$component));
}

/**
 * Creates a warnings external_multiple_structure
 *
 * @return external_multiple_structure
 * @since  Moodle 2.3
 */
function external_warnings() {
    return new external_multiple_structure(
        new external_single_structure(array(
            'item' => new external_value(PARAM_TEXT, 'item', VALUE_OPTIONAL),
            'itemid' => new external_value(PARAM_INT, 'item id', VALUE_OPTIONAL),
            'warningcode' => new external_value(PARAM_ALPHANUM, 'the warning code can be used by
                the client app to implement specific behaviour'),
            'message' => new external_value(PARAM_TEXT, 'untranslated english message to explain the warning')
                ), 'warning'), 'list of warnings', VALUE_OPTIONAL
    );
}
