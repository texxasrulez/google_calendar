<?php

/**
 * Google Calendar driver for the Roundcube Calendar plugin
 * Read-only overlay of a user's Google calendars via OAuth2.
 * Requires google/apiclient:^2.17 (install with composer).
 *
 * This driver implements the calendar_driver interface minimally:
 * - list_calendars(): fetches user's calendars (non-editable)
 * - load_events(): loads events in a date range
 * - get_event(): fetches a single event
 * - count_events(): naive count in a range
 * Other mutating methods return false (non-editable).
 */

use Google\Client as GoogleClient;
use Google\Service\Calendar as GoogleServiceCalendar;

class google_driver extends calendar_driver
{
    /** @var rcmail */
    protected $rc;
    /** @var calendar */
    protected $cal;
    /** @var GoogleClient|null */
    protected $client = null;
    /** @var GoogleServiceCalendar|null */
    protected $gcal = null;
    /** @var array */
    public $alarm_types = ['DISPLAY','EMAIL']; // for settings UI compatibility
    /** @var bool */
    public $nocategories = true; // categories not supported from Google API here

    public function __construct($cal)
    {
        $this->rc  = rcmail::get_instance();
        $this->cal = $cal;

        // Ensure tokens table exists
        $this->ensure_schema();
        $this->init_google_client();
    }

    protected function ensure_schema()
    {
        $db = $this->rc->get_dbh();
        $table = $this->rc->db->table_name('google_oauth_tokens', true);
        $db->query(
            "CREATE TABLE IF NOT EXISTS $table ("
            . " user_id INT NOT NULL,"
            . " account VARCHAR(255) NOT NULL,"
            . " access_token TEXT NOT NULL,"
            . " refresh_token TEXT,"
            . " expires_at INT,"
            . " PRIMARY KEY (user_id, account)"
            . " )"
        );
    }

    protected function init_google_client()
    {
        // Composer autoload (if Roundcube root vendor or plugin vendor)
        $autoloads = [
            INSTALL_PATH . '/vendor/autoload.php',
            $this->cal->home . '/vendor/autoload.php',
        ];
        foreach ($autoloads as $a) {
            if (is_readable($a)) {
                require_once $a;
                break;
            }
        }

        if (!class_exists('\\Google\\Client')) {
            // no library installed; leave $this->client null
            return;
        }

        $client = new GoogleClient();
        $client->setApplicationName('Roundcube Calendar (Google driver)');
        $client->setAccessType('offline');
        $client->setPrompt('consent');
        $client->setIncludeGrantedScopes(true);
        $client->setScopes([GoogleServiceCalendar::CALENDAR_READONLY]);

        // config-driven Client ID/Secret
        $client_id = (string)$this->rc->config->get('calendar_google_client_id', '');
        $client_secret = (string)$this->rc->config->get('calendar_google_client_secret', '');

        if ($client_id && $client_secret) {
            $client->setClientId($client_id);
            $client->setClientSecret($client_secret);
        }

        // Compute redirect URI to our callback handler
        $callback = $this->rc->url([
            '_task'   => 'calendar',
            '_action' => 'plugin.google-oauth-callback',
        ], true, true);

        $client->setRedirectUri($callback);

        $this->client = $client;

        // If user has a stored token, set it up
        $token = $this->load_user_token();
        if ($token) {
            $this->client->setAccessToken($token);
            if ($this->client->isAccessTokenExpired() && isset($token['refresh_token'])) {
                $new = $this->client->fetchAccessTokenWithRefreshToken($token['refresh_token']);
                if (!empty($new['access_token'])) {
                    $merged = array_merge($token, $new);
                    $this->store_user_token($merged);
                    $this->client->setAccessToken($merged);
                }
            }
            $this->gcal = new GoogleServiceCalendar($this->client);
        }
    }

    // ===== Token storage helpers =====

    protected function token_table()
    {
        return $this->rc->db->table_name('google_oauth_tokens', true);
    }

    protected function load_user_token()
    {
        $db = $this->rc->get_dbh();
        $table = $this->token_table();
        $sql = "SELECT access_token, refresh_token, expires_at, account"
             . " FROM $table WHERE user_id = ? ORDER BY account LIMIT 1";
        $res = $db->query($sql, $this->rc->user->ID);
        if ($res && ($row = $db->fetch_assoc($res))) {
            $token = json_decode($row['access_token'], true);
            if (!is_array($token)) {
                // Backward compatibility: store plain token
                $token = ['access_token' => $row['access_token'], 'expires_in' => max(0, $row['expires_at'] - time())];
                if (!empty($row['refresh_token'])) $token['refresh_token'] = $row['refresh_token'];
            }
            return $token;
        }
        return null;
    }

    public function store_user_token($token, $account_email = null)
    {
        $db = $this->rc->get_dbh();
        $table = $this->token_table();
        $expires_at = 0;
        if (isset($token['created'], $token['expires_in'])) {
            $expires_at = intval($token['created']) + intval($token['expires_in']);
        } elseif (isset($token['expires_at'])) {
            $expires_at = intval($token['expires_at']);
        }

        $account = $account_email ?: (isset($token['email']) ? $token['email'] : '');
        $json = json_encode($token);

        // Upsert
        $db->query(
            "INSERT INTO $table (user_id, account, access_token, refresh_token, expires_at) "
            . "VALUES (?, ?, ?, ?, ?) "
            . "ON CONFLICT(user_id, account) DO UPDATE SET access_token = excluded.access_token, refresh_token = excluded.refresh_token, expires_at = excluded.expires_at",
            $this->rc->user->ID, $account, $json,
            isset($token['refresh_token']) ? $token['refresh_token'] : null,
            $expires_at
        );
    }

    public function revoke_user_token()
    {
        $db = $this->rc->get_dbh();
        $table = $this->token_table();
        $db->query("DELETE FROM $table WHERE user_id = ?", $this->rc->user->ID);
        $this->client = null;
        $this->gcal = null;
    }

    // ===== Driver API =====

    public function list_calendars($filter = 0, &$tree = null)
    {
        $result = [];

        if (!$this->gcal) {
            return $result;
        }

        $prefs = (array)$this->rc->user->get_prefs();
        $selected = isset($prefs['calendar_google_selected']) ? (array)$prefs['calendar_google_selected'] : [];

        $calendars = $this->gcal->calendarList->listCalendarList();
        $account = $this->client ? $this->client->getConfig('login_hint') : '';

        foreach ($calendars->getItems() as $c) {
            $id = 'google:' . $c->getId();
            $name = $c->getSummary();
            $color = $c->getBackgroundColor() ?: '#1a73e8';
            $editable = in_array($c->getAccessRole(), ['writer','owner']);

            $result[$id] = [
                'id'        => $id,
                'name'      => rcube::Q($name),
                'listname'  => rcube::Q($name),
                'editname'  => rcube::Q($name),
                'color'     => $color,
                'editable'  => false, // keep read-only for safety
                'group'     => 'google',
                'active'    => empty($selected) ? true : in_array($id, $selected),
                'owner'     => rcube::Q($c->getSummaryOverride() ?: $c->getId()),
                'removable' => false,
            ];
        }

        return $result;
    }

    public function create_calendar($prop) { return false; }
    public function edit_calendar($prop) { return false; }
    public function get_calendar_name($id) { return $id; }
    public function subscribe_calendar($prop) { return false; }
    public function delete_calendar($prop) { return false; }
    public function search_calendars($query, $source) { return []; }

    public function new_event($event) { return false; }
    public function edit_event($event) { return false; }
    public function move_event($event)
    {
        return false;
    }
    public function resize_event($event)
    {
        return false;
    }
    public function remove_event($event, $force = true) { return false; }

    public function get_event($event, $scope = 0, $full = false)
    {
        if (!$this->gcal) return null;
        $id = is_array($event) && isset($event['id']) ? $event['id'] : (string)$event;
        $parts = explode(':', $id, 3);
        if (count($parts) === 3) {
            list(, $calId, $eventId) = $parts;
            $ev = $this->gcal->events->get($calId, $eventId);
            return $this->map_event($ev, $calId);
        }
        return null;
    }
public function load_events($start, $end, $query = null, $calendars = null, $virtual = true, $modifiedsince = null)
    {
        if (!$this->gcal) return [];

        $items = [];

        $list = $this->list_calendars();
        $target_ids = [];

        if (empty($calendars)) {
            $target_ids = array_keys($list);
        } else {
            if (is_string($calendars)) $calendars = explode(',', $calendars);
            $target_ids = array_intersect(array_keys($list), (array)$calendars);
        }

        $params = [
            'singleEvents' => true,
            'orderBy'      => 'startTime',
            'timeMin'      => gmdate('c', $start),
            'timeMax'      => gmdate('c', $end),
            'maxResults'   => 2500,
        ];
        if (!empty($query)) $params['q'] = $query;

        foreach ($target_ids as $id) {
            $calId = substr($id, 7); // remove "google:"
            $pageToken = null;
            do {
                $resp = $this->gcal->events->listEvents($calId, $params);
                foreach ($resp->getItems() as $ev) {
                    $items[] = $this->map_event($ev, $calId);
                }
                $pageToken = $resp->getNextPageToken();
                $params['pageToken'] = $pageToken;
            } while ($pageToken);
        }

        return $items;
    }

    public function count_events($calendars, $start, $end = null)
    {
        if ($end === null) {
            // default: one day range
            $end = $start + 86400;
        }
        $events = $this->load_events($start, $end, null, $calendars, true, null);
        return count($events);
    }

    public function pending_alarms($time, $calendars = null) { return []; }
    public function dismiss_alarm($event_id, $snooze = 0)
    {
        // Google API alarms not surfaced; nothing to do
        return 0;
    }

    // ===== Mapping =====
    protected function map_event(\Google\Service\Calendar\Event $ev, $calId)
    {
        $start = $ev->getStart();
        $end   = $ev->getEnd();
        $allday = (bool)$start->getDate();

        $start_dt = $start->getDateTime() ?: ($start->getDate() . 'T00:00:00Z');
        $end_dt   = $end->getDateTime() ?: ($end->getDate() . 'T00:00:00Z');

        $s = new DateTime($start_dt);
        $e = new DateTime($end_dt);

        $event = [
            'id'         => 'google:' . $calId . ':' . $ev->getId(),
            'uid'        => (string)$ev->getICalUID() ?: $ev->getId(),
            'calendar'   => 'google:' . $calId,
            'title'      => (string)$ev->getSummary(),
            'description'=> (string)$ev->getDescription(),
            'location'   => (string)$ev->getLocation(),
            'start'      => $s,
            'end'        => $e,
            'all_day'    => $allday ? 1 : 0,
            'categories' => [],
            'free_busy'  => 'busy',
            'status'     => strtoupper($ev->getStatus() ?: 'CONFIRMED'),
            'priority'   => 0,
            'attendees'  => [],
            'alarms'     => [],
            'valarms'    => [],
            'flags'      => ['readonly'],
        ];

        // attendees
        $atts = $ev->getAttendees();
        if (is_array($atts)) {
            foreach ($atts as $a) {
                $event['attendees'][] = [
                    'name'  => (string)$a->getDisplayName(),
                    'email' => (string)$a->getEmail(),
                    'role'  => 'REQ-PARTICIPANT',
                    'status'=> strtoupper($a->getResponseStatus() ?: 'NEEDS-ACTION'),
                ];
            }
        }

        return $event;
    }

    // ===== OAuth helpers (used by calendar.php action handlers) =====

    public function oauth_auth_url()
    {
        if (!$this->client) return null;
        return $this->client->createAuthUrl();
    }

    public function oauth_handle_callback()
    {
        if (!$this->client) return ['ok' => false, 'error' => 'client_not_ready'];

        $code = rcube_utils::get_input_value('code', rcube_utils::INPUT_GPC);
        if (!$code) return ['ok' => false, 'error' => 'missing_code'];

        $token = $this->client->fetchAccessTokenWithAuthCode($code);
        if (isset($token['error'])) {
            return ['ok' => false, 'error' => $token['error']];
        }

        // Get account email
        $oauth2 = new Google\Service\Oauth2($this->client);
        $userinfo = $oauth2->userinfo->get();
        $email = $userinfo->getEmail();

        $token['email'] = $email;
        $this->store_user_token($token, $email);
        $this->gcal = new GoogleServiceCalendar($this->client);

        return ['ok' => true, 'email' => $email];
    }
}