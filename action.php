<?php
/**
 * Plugin Logstats - J.-F. Lalande
 *
 * @license    GPL 2 (http://www.gnu.org/licenses/gpl.html)
 * @author Matthias Grimm <matthiasgrimm@users.sourceforge.net>
 * @author     J.-F. Lalande <jf@lalande.nom.fr>
 */
if(!defined('DOKU_INC')) define('DOKU_INC', realpath(dirname(__FILE__).'/../../').'/');

/**
 * All DokuWiki plugins to extend the parser/rendering mechanism
 * need to inherit from this class
 */
class action_plugin_logstats extends DokuWiki_Action_Plugin {

    /**
     * Register its handlers with the dokuwiki's event controller
     */
    public function register(Doku_Event_Handler $controller) {
        $controller->register_hook('ACTION_HEADERS_SEND', 'BEFORE', $this, 'logPageAccess');
        $controller->register_hook('FETCH_MEDIA_STATUS', 'BEFORE', $this, 'logMediaAccess');
    }

    /**
     * Logs access to a wiki page (only show mode)
     *
     * @param Doku_Event $event
     */
    public function logPageAccess($event) {
        global $ID;
        global $ACT;

        if($ACT != 'show') return;

        $page = $this->prepareID($ID);

        $crumbs = breadcrumbs(); // get last visited pages
        $crumbs = array_keys($crumbs); // get raw page IDs
        array_pop($crumbs); // skip current page
        $referer = array_pop($crumbs); // get current page's predecessor
        $referer = ($referer) ? $this->prepareID($referer) : '';

        $size   = @filesize(wikiFN($ID));
        $status = $size ? 200 : 404;

        $this->logAccess($page, $status, $size, $referer);
    }

    /**
     * logs access to a media file
     *
     * @param Doku_Event $event
     */
    public function logMediaAccess($event) {
        // don't log external stuff
        if(preg_match('#^(https?|ftp)://#i', $event->data['media'])) return;

        $media  = $this->prepareID($event->data['media']);
        $status = $event->data['status'];
        $size   = @filesize($event->data['file']);

        $this->logAccess("/_media/$media", $status, $size);
    }

    /**
     * beautify a wiki page id for the log
     *
     * The wiki page id will be transformed to a filename like string
     * utf8 codes will be encoded.
     *
     * @author Matthias Grimm <matthiasgrimm@users.sourceforge.net>
     * @param string $path wiki page id
     * @return mixed|string
     */
    protected function prepareID($path) {
        $path = cleanID($path);
        $path = str_replace(':', '/', $path);
        $path = utf8_encodeFN($path);
        return $path;
    }

    /**
     * creates a log file entry and writes it to the log
     *
     * This function writes access information of the current page to a log
     * file. It uses the combined log file format that is also used by the
     * apache web server. A whole bunch of available log analysers could be
     * used to visualize the log.
     *
     *
     * combined log file format:
     *     <host> <rfc931> <user> [<timestamp>] "<request>" <error> <filesize>
     *               "<referer>" "<agent>"\n
     *
     * <host>      IP of the client host (we don't do reverse host lookups)
     * <rfc931>    remote user identification or '-' if not available
     * <user>      user id or '-' if not available
     * <timestamp> time in format [01/Dec/2005:22:19:12 +0200]
     * <request>   Requested protocol, for eg. GET or POST, requested page
     *             and protocol
     * <error>     error code from server, for eg. 200 (OK) or 404 (file
     *             not found)
     * <filesize>  size of the wiki page (only the bare text)
     * <referer>   page that called this one. We don't have this information
     *             and filled the dokuwiki script name in.
     * <agent>     identifying information that the client browser reports
     *             about itself
     *
     * @author Matthias Grimm <matthiasgrimm@users.sourceforge.net>
     *
     * @param  string $page     page name that was called
     * @param  int    $status   HTTP status code
     * @param  int    $size     file size
     * @param  string $referer  predecessor of $page (which page link to $page)
     *                          Is this field empty, the functions tries to get
     *                          the referer from the web server (HTTP_REFERER)
     * @return bool true if a log entry was written
     */
    public function logAccess($page, $status, $size, $referer = '') {
        global $conf;

        $host      = clientIP(true);
        $user      = isset($_SERVER['REMOTE_USER']) ? $_SERVER['REMOTE_USER'] : "-";
        $timestamp = date("[d/M/Y:H:i:s O]");
        $method    = isset($_SERVER['REQUEST_METHOD']) ? $_SERVER['REQUEST_METHOD'] : "";
        $protocol  = isset($_SERVER['SERVER_PROTOCOL']) ? $_SERVER['SERVER_PROTOCOL'] : "";
        $agent     = isset($_SERVER['HTTP_USER_AGENT']) ? $_SERVER['HTTP_USER_AGENT'] : "";

        // We have to check if this agent is not banned
        if($this->getConf('banned_agents') != "") {
            $tmp_array_agents_banned = explode(',', $this->getConf('banned_agents'));
            foreach($tmp_array_agents_banned as $agents_banned) {
                if(stristr($agent, $agents_banned) !== false) {
                    return false; // exit the function, nothing have to be written
                }
            }
        }

        // We have to check if this IP is not banned
        if($this->getConf('banned_ip') != "") {
            $tmp_array_ip_banned = explode(',', $this->getConf('banned_ip'));
            foreach($tmp_array_ip_banned as $ip_banned) {
                if(strcmp($host, $ip_banned) == 0) {
                    return false; // exit the function, nothing have to be written
                }
            }
        }

        // Banned some users
        if($this->getConf('banned_users') != "") {
            $tmp_array_users_banned = explode(',', $this->getConf('banned_users'));
            foreach($tmp_array_users_banned as $users_banned) {
                if(strcmp($user, $users_banned) == 0) {
                    return false; // exit the function, nothing have to be written
                }
            }
        }

        // Analyzing referer
        if($referer == "") {
            //echo "referrer: " . $_SERVER['HTTP_REFERER'];
            if(isset($_SERVER['HTTP_REFERER'])) {
                $cnt = preg_match('/\?id=((\w+\:*)+)/i', $_SERVER['HTTP_REFERER'], $match);
                if($cnt == 1) {
                    $referer = $this->prepareID($match[1]);
                } else {
                    $referer = $_SERVER['HTTP_REFERER'];
                }
            }
        }
        $logline = "$host - $user $timestamp \"$method $page $protocol\" $status $size \"$referer\" \"$agent\"\n";

        // determine log, relative paths resolve to meta dir
        $dir = dirname($this->getConf('accesslog'));
        $log = basename($this->getConf('accesslog'));
        if($dir == '.' || $dir == '' || !is_dir($dir)){
            $dir = fullpath($conf['metadir'].'/'.$dir);
        }

        return io_saveFile("$dir/$log", $logline, true);
    }

} // End of class

