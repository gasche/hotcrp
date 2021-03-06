<?php
// userstatus.php -- HotCRP helpers for reading/storing users as JSON
// Copyright (c) 2008-2018 Eddie Kohler; see LICENSE.

class UserStatus extends MessageSet {
    public $conf;
    public $user;
    public $viewer;
    public $send_email = null;
    private $no_deprivilege_self = false;
    public $unknown_topics = null;
    private $_gxt;

    static private $field_synonym_map = [
        "preferredEmail" => "preferred_email",
        "voicePhoneNumber" => "phone",
        "addressLine1" => "address", "addressLine2" => "address",
        "zipCode" => "zip", "postal_code" => "zip",
        "contactTags" => "tags", "uemail" => "email"
    ];

    static public $topic_interest_name_map = [
        "low" => -2,
        "mlow" => -1, "mediumlow" => -1, "medium-low" => -1, "medium_low" => -1,
        "medium" => 0, "none" => 0,
        "mhigh" => 2, "mediumhigh" => 2, "medium-high" => 2, "medium_high" => 2,
        "high" => 4
    ];

    function __construct(Contact $viewer, $options = array()) {
        $this->conf = $viewer->conf;
        $this->viewer = $viewer;
        parent::__construct();
        foreach (array("send_email", "no_deprivilege_self") as $k)
            if (array_key_exists($k, $options))
                $this->$k = $options[$k];
        foreach (self::$field_synonym_map as $src => $dst)
            $this->translate_field($src, $dst);
    }
    function clear() {
        $this->clear_messages();
        $this->unknown_topics = null;
    }
    private function gxt() {
        if ($this->_gxt === null)
            $this->_gxt = new GroupedExtensions($this->viewer, ["etc/profilegroups.json"], $this->conf->opt("profileGroups"));
        return $this->_gxt;
    }

    static function unparse_roles_json($roles) {
        if ($roles) {
            $rj = (object) array();
            if ($roles & Contact::ROLE_CHAIR)
                $rj->chair = $rj->pc = true;
            if ($roles & Contact::ROLE_PC)
                $rj->pc = true;
            if ($roles & Contact::ROLE_ADMIN)
                $rj->sysadmin = true;
            return $rj;
        } else
            return null;
    }

    static function unparse_json_main(UserStatus $us, $cj, $args) {
        // keys that might come from user or contactdb
        $user = $us->user;
        $cdb_user = $user->contactdb_user();
        foreach (["email", "firstName", "lastName", "affiliation",
                  "collaborators", "country"] as $k) {
            if ($user->$k !== null && $user->$k !== "")
                $cj->$k = $user->$k;
            else if ($cdb_user && $cdb_user->$k !== null && $cdb_user->$k !== "")
                $cj->$k = $cdb_user->$k;
        }

        // keys that come from user
        foreach (["preferredEmail" => "preferred_email",
                  "voicePhoneNumber" => "phone"] as $uk => $jk) {
            if ($user->$uk !== null && $user->$uk !== "")
                $cj->$jk = $user->$uk;
        }

        if ($user->disabled)
            $cj->disabled = true;

        foreach (["address", "city", "state", "zip", "country"] as $k) {
            if (($x = $user->data($k)))
                $cj->$k = $x;
        }

        if (get($args, "include_password")
            && ($pw = $user->plaintext_password()))
            $cj->__passwords = ["", "", $pw];

        if ($user->roles)
            $cj->roles = self::unparse_roles_json($user->roles);

        if ($user->defaultWatch) {
            $cj->follow = (object) array();
            if ($user->defaultWatch & (WATCHTYPE_COMMENT << WATCHSHIFT_ON))
                $cj->follow->reviews = true;
            if ($user->defaultWatch & (WATCHTYPE_COMMENT << WATCHSHIFT_ALLON))
                $cj->follow->reviews = $cj->follow->allreviews = true;
            if ($user->defaultWatch & (WATCHTYPE_FINAL_SUBMIT << WATCHSHIFT_ALLON))
                $cj->follow->allfinal = true;
        }

        if (($tags = $user->viewable_tags($us->viewer))) {
            $tagger = new Tagger($us->viewer);
            $cj->tags = explode(" ", $tagger->unparse($tags));
        }

        if ($user->contactId && ($tm = $user->topic_interest_map()))
            $cj->topics = (object) $tm;
    }

    function user_json($args = []) {
        if ($this->user) {
            $cj = (object) [];
            if ($this->user->contactId > 0)
                $cj->id = $this->user->contactId;
            foreach ($this->gxt()->groups() as $gj)
                if (isset($gj->unparse_json_callback)) {
                    Conf::xt_resolve_require($gj);
                    call_user_func($gj->unparse_json_callback, $this, $cj, $args);
                }
            return $cj;
        } else {
            return null;
        }
    }


    private function make_keyed_object($x, $field) {
        if (is_string($x))
            $x = preg_split('/[\s,]+/', $x);
        $res = (object) array();
        if (is_array($x)) {
            foreach ($x as $v)
                if (!is_string($v))
                    $this->error_at($field, "Format error [$field]");
                else if ($v !== "")
                    $res->$v = true;
        } else if (is_object($x))
            $res = $x;
        else
            $this->error_at($field, "Format error [$field]");
        return $res;
    }

    static function normalize_name($cj) {
        $cj_user = isset($cj->user) ? Text::split_name($cj->user, true) : null;
        $cj_name = Text::analyze_name($cj);
        foreach (array("firstName", "lastName", "email") as $i => $k)
            if ($cj_name->$k !== "" && $cj_name->$k !== false)
                $cj->$k = $cj_name->$k;
            else if ($cj_user && $cj_user[$i])
                $cj->$k = $cj_user[$i];
    }

    private function make_tags_array($x, $key) {
        $t0 = array();
        if (is_string($x))
            $t0 = preg_split('/[\s,]+/', $x);
        else if (is_array($x))
            $t0 = $x;
        else if ($x !== null)
            $this->error_at($key, "Format error [$key]");
        $tagger = new Tagger;
        $t1 = array();
        foreach ($t0 as $t)
            if ($t !== "" && ($t = $tagger->check($t, Tagger::NOPRIVATE)))
                $t1[] = $t;
            else if ($t !== "")
                $this->error_at($key, $tagger->error_html);
        return $t1;
    }

    private function normalize($cj, $old_user) {
        // Errors prevent saving
        global $Me, $Now;

        // Canonicalize keys
        foreach (array("preferredEmail" => "preferred_email",
                       "institution" => "affiliation",
                       "voicePhoneNumber" => "phone",
                       "addressLine1" => "address",
                       "zipCode" => "zip", "postal_code" => "zip") as $x => $y)
            if (isset($cj->$x) && !isset($cj->$y))
                $cj->$y = $cj->$x;

        // Stringiness
        foreach (array("firstName", "lastName", "email", "preferred_email",
                       "affiliation", "phone", "old_password", "new_password",
                       "city", "state", "zip", "country") as $k)
            if (isset($cj->$k) && !is_string($cj->$k)) {
                $this->error_at($k, "Format error [$k]");
                unset($cj->$k);
            }

        // Email
        if (!get($cj, "email") && $old_user)
            $cj->email = $old_user->email;
        else if (!get($cj, "email"))
            $this->error_at("email", "Email is required.");
        else if (!$this->has_problem_at("email")
                 && !validate_email($cj->email)
                 && (!$old_user || $old_user->email !== $cj->email))
            $this->error_at("email", "Invalid email address “" . htmlspecialchars($cj->email) . "”.");

        // ID
        if (get($cj, "id") === "new") {
            if (get($cj, "email") && $this->conf->user_id_by_email($cj->email)) {
                $this->error_at("email", "Email address “" . htmlspecialchars($cj->email) . "” is already in use.");
                $this->error_at("email_inuse", false);
            }
        } else {
            if (!get($cj, "id") && $old_user && $old_user->contactId)
                $cj->id = $old_user->contactId;
            if (get($cj, "id") && !is_int($cj->id))
                $this->error_at("id", "Format error [id]");
            if ($old_user && get($cj, "email")
                && strtolower($old_user->email) !== strtolower($cj->email)
                && $this->conf->user_id_by_email($cj->email))
                $this->error_at("email", "Email address “" . htmlspecialchars($cj->email) . "” is already in use. You may want to <a href=\"" . hoturl("mergeaccounts") . "\">merge these accounts</a>.");
        }

        // Contactdb information
        if ($old_user && !$old_user->contactId) {
            if (!isset($cj->firstName) && !isset($cj->lastName)) {
                $cj->firstName = $old_user->firstName;
                $cj->lastName = $old_user->lastName;
            }
            if (!isset($cj->affiliation))
                $cj->affiliation = $old_user->affiliation;
            if (!isset($cj->collaborators))
                $cj->collaborators = $old_user->collaborators;
        }

        // Password changes
        if (isset($cj->new_password) && $old_user && $old_user->data("locked")) {
            unset($cj->new_password);
            $this->warning_at("password", "Ignoring request to change locked user’s password.");
        }

        // Preferred email
        if (get($cj, "preferred_email")
            && !$this->has_problem_at("preferred_email")
            && !validate_email($cj->preferred_email)
            && (!$old_user || $old_user->preferredEmail !== $cj->preferred_email))
            $this->error_at("preferred_email", "Invalid email address “" . htmlspecialchars($cj->preferred_email) . "”");

        // Address
        $address = array();
        if (is_array(get($cj, "address")))
            $address = $cj->address;
        else {
            if (is_string(get($cj, "address")))
                $address[] = $cj->address;
            else if (get($cj, "address"))
                $this->error_at("address", "Format error [address]");
            if (is_string(get($cj, "address2")))
                $address[] = $cj->address2;
            else if (is_string(get($cj, "addressLine2")))
                $address[] = $cj->addressLine2;
            else if (get($cj, "address2") || get($cj, "addressLine2"))
                $this->error_at("address2", "Format error [address2]");
        }
        foreach ($address as $a)
            if (!is_string($a))
                $this->error_at("address", "Format error [address]");
        if (!empty($address))
            $cj->address = $address;

        // Collaborators
        if (is_array(get($cj, "collaborators"))) {
            foreach ($cj->collaborators as $c)
                if (!is_string($c))
                    $this->error_at("collaborators", "Format error [collaborators]");
            if (!$this->has_problem_at("collaborators"))
                $cj->collaborators = join("\n", $cj->collaborators);
        }
        if (get($cj, "collaborators") && !is_string($cj->collaborators)
            && !$this->has_problem_at("collaborators"))
            $this->error_at("collaborators", "Format error [collaborators]");

        // Disabled
        if (isset($cj->disabled)) {
            if (($x = friendly_boolean($cj->disabled)) !== null)
                $cj->disabled = $x;
            else
                $this->error_at("disabled", "Format error [disabled]");
        }

        // Follow
        if (isset($cj->follow)) {
            $cj->follow = $this->make_keyed_object($cj->follow, "follow");
            $cj->bad_follow = array();
            foreach ((array) $cj->follow as $k => $v)
                if ($v && $k !== "reviews" && $k !== "allreviews" && $k !== "allfinal")
                    $cj->bad_follow[] = $k;
        }

        // Roles
        if (isset($cj->roles)) {
            $cj->roles = $this->make_keyed_object($cj->roles, "roles");
            $cj->bad_roles = array();
            foreach ((array) $cj->roles as $k => $v)
                if ($v && $k !== "pc" && $k !== "chair" && $k !== "sysadmin"
                    && $k !== "no")
                    $cj->bad_roles[] = $k;
            if ($old_user
                && (($this->no_deprivilege_self
                     && $Me
                     && $Me->conf === $this->conf
                     && $Me->contactId == $old_user->contactId)
                    || $old_user->data("locked"))
                && Contact::parse_roles_json($cj->roles) < $old_user->roles) {
                unset($cj->roles);
                if ($old_user->data("locked"))
                    $this->warning_at("roles", "Ignoring request to drop privileges for locked account.");
                else
                    $this->warning_at("roles", "Ignoring request to drop your privileges.");
            }
        }

        // Tags
        if (isset($cj->tags))
            $cj->tags = $this->make_tags_array($cj->tags, "tags");
        if (isset($cj->add_tags) || isset($cj->remove_tags)) {
            // collect old tags as map by base
            if (!isset($cj->tags) && $old_user)
                $cj->tags = preg_split('/[\s,]+/', $old_user->contactTags);
            else if (!isset($cj->tags))
                $cj->tags = array();
            $old_tags = array();
            foreach ($cj->tags as $t)
                if ($t !== "") {
                    list($tag, $index) = TagInfo::unpack($t);
                    $old_tags[$tag] = $index;
                }
            // process removals, then additions
            foreach ($this->make_tags_array(get($cj, "remove_tags"), "remove_tags") as $t) {
                list($tag, $index) = TagInfo::unpack($t);
                if ($index === false || get($old_tags, $tag) == $index)
                    unset($old_tags[$tag]);
            }
            foreach ($this->make_tags_array(get($cj, "add_tags"), "add_tags") as $t) {
                list($tag, $index) = TagInfo::unpack($t);
                $old_tags[$tag] = $index;
            }
            // collect results
            $cj->tags = array();
            foreach ($old_tags as $tag => $index)
                $cj->tags[] = $tag . "#" . (float) $index;
        }

        // Topics
        $in_topics = null;
        if (isset($cj->topics))
            $in_topics = $this->make_keyed_object($cj->topics, "topics");
        else if (isset($cj->change_topics))
            $in_topics = $this->make_keyed_object($cj->change_topics, "change_topics");
        if ($in_topics !== null) {
            $topics = !isset($cj->topics) && $old_user ? $old_user->topic_interest_map() : [];
            $cj->bad_topics = array();
            foreach ((array) $in_topics as $k => $v) {
                if (get($this->conf->topic_map(), $k))
                    $k = (int) $k;
                else if (($tid = $this->conf->topic_abbrev_matcher()->find1($k)))
                    $k = $tid;
                else {
                    $cj->bad_topics[] = $k;
                    continue;
                }
                if (is_bool($v))
                    $v = $v ? 2 : 0;
                else if (is_string($v) && isset(self::$topic_interest_name_map[$v]))
                    $v = self::$topic_interest_name_map[$v];
                else if (is_numeric($v))
                    $v = (int) $v;
                else {
                    $this->error_at("topics", "Topic interest format error");
                    continue;
                }
                $topics[$k] = $v;
            }
            $cj->topics = (object) $topics;
        }
    }

    function check_invariants($cj) {
        global $Now;
        if (isset($cj->bad_follow) && count($cj->bad_follow))
            $this->warning_at("follow", "Unknown follow types ignored (" . htmlspecialchars(commajoin($cj->bad_follow)) . ").");
        if (isset($cj->bad_topics) && count($cj->bad_topics))
            $this->warning_at("topics", "Unknown topics ignored (" . htmlspecialchars(commajoin($cj->bad_topics)) . ").");
    }


    function save($cj, $old_user = null, $actor = null) {
        global $Now;
        assert(is_object($cj));
        self::normalize_name($cj);
        $nerrors = $this->nerrors();

        if (!$old_user && is_int(get($cj, "id")) && $cj->id)
            $old_user = $this->conf->user_by_id($cj->id);
        else if (!$old_user && is_string(get($cj, "email")) && $cj->email)
            $old_user = $this->conf->user_by_email($cj->email);
        if (!get($cj, "id"))
            $cj->id = $old_user ? $old_user->contactId : "new";
        if ($cj->id !== "new" && $old_user && $cj->id != $old_user->contactId) {
            $this->error_at("id", "Saving user with different ID");
            return false;
        }

        $no_old_db_account = !$old_user || !$old_user->has_database_account();
        $old_cdb_user = null;
        if ($old_user && $old_user->has_email())
            $old_cdb_user = Contact::contactdb_find_by_email($old_user->email);
        else if (is_string(get($cj, "email")) && $cj->email)
            $old_cdb_user = Contact::contactdb_find_by_email($cj->email);
        $user = $old_user ? : $old_cdb_user;

        $this->normalize($cj, $user);
        if ($this->nerrors() > $nerrors)
            return false;
        $this->check_invariants($cj);

        $user = $user ? : new Contact(null, $this->conf);
        if (($send = $this->send_email) === null)
            $send = !$old_cdb_user;
        if ($user->save_json($cj, $actor, $send))
            return $user;
        else
            return false;
    }


    static function parse_request_main(UserStatus $us, $cj, Qrequest $qreq, $uf) {
        // email
        if (!$us->conf->external_login())
            $cj->email = trim((string) $qreq->uemail);
        else if ($us->user->is_empty())
            $cj->email = trim((string) $qreq->newUsername);
        else
            $cj->email = $us->user->email;

        // normal fields
        foreach (["firstName", "lastName", "preferredEmail", "affiliation",
                  "collaborators", "addressLine1", "addressLine2",
                  "city", "state", "zipCode", "country", "voicePhoneNumber"] as $k) {
            $v = $qreq[$k];
            if ($v !== null && ($cj->id !== "new" || trim($v) !== ""))
                $cj->$k = $v;
        }

        // password
        if (!$us->conf->external_login()
            && !$us->user->is_empty()
            && $us->viewer->can_change_password($us->user)
            && (isset($qreq->upassword) || isset($qreq->upasswordt))) {
            if ($qreq->whichpassword === "t" && $qreq->upasswordt)
                $pw = $pw2 = trim($qreq->upasswordt);
            else {
                $pw = trim((string) $qreq->upassword);
                $pw2 = trim((string) $qreq->upassword2);
            }
            $cj->__passwords = [(string) $qreq->upassword, (string) $qreq->upassword2, (string) $qreq->upasswordt];
            if ($pw === "" && $pw2 === "")
                /* do nothing */;
            else if ($pw !== $pw2)
                $us->error_at("password", "Those passwords do not match.");
            else if (!Contact::valid_password($pw))
                $us->error_at("password", "Invalid new password.");
            else if ($us->viewer->can_change_password(null)) {
                $cj->old_password = null;
                $cj->new_password = $pw;
            } else {
                $cj->old_password = trim((string) $qreq->oldpassword);
                if ($us->user->check_password($cj->old_password))
                    $cj->new_password = $pw;
                else
                    $us->error_at("password", "Incorrect current password. New password ignored.");
            }
        }

        // PC components
        if (isset($qreq->pctype) && $us->viewer->privChair) {
            $cj->roles = (object) array();
            $pctype = $qreq->pctype;
            if ($pctype === "chair")
                $cj->roles->chair = $cj->roles->pc = true;
            if ($pctype === "pc")
                $cj->roles->pc = true;
            if ($qreq->ass)
                $cj->roles->sysadmin = true;
        }

        $follow = [];
        if ($qreq->has_watchcomment)
            $follow["reviews"] = !!$qreq->watchcomment;
        if ($qreq->has_watchcommentall && ($us->viewer->privChair || $us->user->isPC))
            $follow["allreviews"] = !!$qreq->watchcommentall;
        if ($qreq->has_watchfinalall && $us->viewer->privChair)
            $follow["allfinal"] = !!$qreq->watchfinalall;
        if (!empty($follow))
            $cj->follow = (object) $follow;

        if (isset($qreq->contactTags) && $us->viewer->privChair)
            $cj->tags = explode(" ", simplify_whitespace($qreq->contactTags));

        if (isset($qreq->has_ti) && $us->viewer->isPC) {
            $topics = array();
            foreach ($us->conf->topic_map() as $id => $t)
                if (isset($qreq["ti$id"]) && is_numeric($qreq["ti$id"]))
                    $topics[$id] = (int) $qreq["ti$id"];
            $cj->topics = (object) $topics;
        }
    }

    function parse_request_topic($g, $cj, Qrequest $qreq) {
        foreach ($this->gxt()->members(strtolower($g)) as $gj) {
            if (isset($gj->parse_request_callback)) {
                Conf::xt_resolve_require($gj);
                call_user_func($gj->parse_request_callback, $this, $cj, $qreq, $gj);
            }
        }
    }


    static private $csv_keys = [
        ["email"],
        ["user"],
        ["firstName", "firstname", "first"],
        ["lastName", "lastname", "last"],
        ["name"],
        ["preferred_email", "preferredEmail", "preferred email"],
        ["affiliation"],
        ["collaborators"],
        ["address1", "addressLine1"],
        ["address2", "addressLine2"],
        ["city"],
        ["state", "province", "region"],
        ["zip", "zipcode", "zipCode", "zip_code", "postalcode", "postal_code"],
        ["country"],
        ["roles"],
        ["follow"],
        ["tags"]
    ];

    static function parse_csv_main(UserStatus $us, $cj, $line, $uf) {
        foreach (self::$csv_keys as $ks) {
            foreach ($ks as $k)
                if (isset($line[$k]) && ($v = trim($line[$k])) !== "") {
                    $kx = $ks[0];
                    $cj->$kx = $v;
                    break;
                }
        }
        if (isset($line["address"]) && ($v = trim($line["address"])) !== "")
            $cj->address = explode("\n", cleannl($line["address"]));

        // topics
        if ($us->conf->has_topics()) {
            $topics = [];
            foreach ($line as $k => $v)
                if (preg_match('/^topic[:\s]\s*(.*?)\s*$/i', $k, $m)) {
                    if (($tid = $us->conf->topic_abbrev_matcher()->find1($m[1]))) {
                        $v = trim($v);
                        $topics[$tid] = $v === "" ? 0 : $v;
                    } else
                        $us->unknown_topics[$m[1]] = true;
                }
            if (!empty($topics))
                $cj->change_topics = (object) $topics;
        }
    }

    function parse_csv_topic($g, $cj, $line) {
        foreach ($this->gxt()->members(strtolower($g)) as $gj) {
            if (isset($gj->parse_csv_callback)) {
                Conf::xt_resolve_require($gj);
                call_user_func($gj->parse_csv_callback, $this, $cj, $line, $gj);
            }
        }
    }


    function render_field($field, $caption, $entry) {
        echo '<div class="', $this->control_class($field, "f-i"), '">',
            ($field ? Ht::label($caption, $field) : '<div class="f-c">' . $caption . '</div>'),
            $entry, "</div>";
    }

    static function pcrole_text($cj) {
        if (isset($cj->roles)) {
            if (isset($cj->roles->chair) && $cj->roles->chair)
                return "chair";
            else if (isset($cj->roles->pc) && $cj->roles->pc)
                return "pc";
        }
        return "no";
    }

    static function render_main(UserStatus $us, $cj, $reqj, $uf) {
        $actas = "";
        if ($us->user !== $us->viewer
            && $us->user->email
            && $us->viewer->privChair)
            $actas = '&nbsp;' . actas_link($us->user);

        echo "<div class=\"profile-g\">\n";
        if (!$us->conf->external_login()) {
            $us->render_field("uemail", "Email" . $actas,
                Ht::entry("uemail", get_s($reqj, "email"), ["class" => "want-focus fullw", "size" => 52, "id" => "uemail", "data-default-value" => get_s($cj, "email")]));
        } else if (!$us->user->is_empty()) {
            $us->render_field(false, "Username" . $actas,
                htmlspecialchars(get_s($cj, "email")));
            $us->render_field("preferredEmail", "Email",
                Ht::entry("preferredEmail", get_s($reqj, "preferred_email"), ["class" => "want-focus fullw", "size" => 52, "id" => "preferredEmail", "data-default-value" => get_s($cj, "preferred_email")]));
        } else {
            $us->render_field("uemail", "Username",
                Ht::entry("newUsername", get_s($reqj, "email"), ["class" => "want-focus fullw", "size" => 52, "id" => "uemail", "data-default-value" => get_s($cj, "email")]));
            $us->render_field("preferredEmail", "Email",
                      Ht::entry("preferredEmail", get_s($reqj, "preferred_email"), ["class" => "fullw", "size" => 52, "id" => "preferredEmail", "data-default-value" => get_s($cj, "preferred_email")]));
        }

        echo '<div class="f-2col">';
        $us->render_field("firstName", "First name",
                  Ht::entry("firstName", get_s($reqj, "firstName"), ["size" => 24, "autocomplete" => "given-name", "class" => "fullw", "id" => "firstName", "data-default-value" => get_s($cj, "firstName")]));
        $us->render_field("lastName", "Last name",
                  Ht::entry("lastName", get_s($reqj, "lastName"), ["size" => 24, "autocomplete" => "family-name", "class" => "fullw", "id" => "lastName", "data-default-value" => get_s($cj, "lastName")]));
        echo '</div>';

        $us->render_field("affiliation", "Affiliation",
                  Ht::entry("affiliation", get_s($reqj, "affiliation"), ["size" => 52, "autocomplete" => "organization", "class" => "fullw", "id" => "affiliation", "data-default-value" => get_s($cj, "affiliation")]));

        echo "</div>\n\n"; // .profile-g
    }

    static function render_password(UserStatus $us, $cj, $reqj, $uf) {
        if ($us->user->is_empty()
            || $us->conf->external_login()
            || !$us->viewer->can_change_password($us->user))
            return;

        echo '<div id="foldpassword" class="profile-g foldc ',
            ($us->has_problem_at("password") ? "fold3o" : "fold3c"),
            '">';
        $pws = get($reqj, "__passwords", ["", "", ""]);
        // Hit a button to change your password
        echo Ht::button("Change password", ["class" => "btn ui js-foldup fn3", "data-fold-target" => "3o"]);
        // Display the following after the button is clicked
        echo '<div class="fx3">';
        if (!$us->viewer->can_change_password(null)) {
            echo '<div class="f-h">Enter your current password as well as your desired new password.</div>';
            echo '<div class="', $us->control_class("password", "f-i"), '"><div class="f-c">Current password</div>',
                Ht::password("oldpassword", "", ["size" => 52, "autocomplete" => "current-password"]),
                '</div>';
        }
        if ($us->conf->opt("contactdb_dsn") && $us->conf->opt("contactdb_loginFormHeading"))
            echo $us->conf->opt("contactdb_loginFormHeading");
        echo '<div class="', $us->control_class("password", "f-i"), '">
      <div class="f-c">New password</div>',
            Ht::password("upassword", $pws[0], ["size" => 52, "class" => "fn", "autocomplete" => "new-password"]);
        if ($us->user->plaintext_password() && $us->viewer->privChair) {
            echo Ht::entry("upasswordt", $pws[2], ["size" => 52, "class" => "fx", "autocomplete" => "new-password"]);
        }
        echo '</div>
    <div class="', $us->control_class("password", "f-i"), ' fn">
      <div class="f-c">Repeat new password</div>',
            Ht::password("upassword2", $pws[1], ["size" => 52]), "</div>\n";
        if ($us->user->plaintext_password()
            && ($us->viewer->privChair || Contact::password_storage_cleartext())) {
            echo "  <div class=\"f-h\">";
            if (Contact::password_storage_cleartext())
                echo "The password is stored in our database in cleartext and will be mailed to you if you have forgotten it, so don’t use a login password or any other high-security password.";
            if ($us->viewer->privChair) {
                if (Contact::password_storage_cleartext())
                    echo " <span class=\"sep\"></span>";
                echo '<span class="n"><a class="ui js-plaintext-password" href=""><span class="fn">Show password</span><span class="fx">Hide password</span></a></span>';
            }
            echo "</div>\n";
        }
        echo "</div></div>"; // .fx3 #foldpassword
    }

    static function render_demographics(UserStatus $us, $cj, $reqj, $uf) {
        $us->render_field("country", "Country", Countries::selector("country", get_s($reqj, "country"), ["id" => "country", "data-default-value" => get_s($cj, "country")]));
    }

    static function render_follow(UserStatus $us, $cj, $reqj, $uf) {
        echo '<h3 class="profile">Email notification</h3>';
        $follow = isset($reqj->follow) ? $reqj->follow : (object) [];
        $cfollow = isset($cj->follow) ? $cj->follow : (object) [];
        echo Ht::hidden("has_watchcomment", 1);
        if ($us->user->is_empty() ? $us->viewer->privChair : $us->user->isPC) {
            echo Ht::hidden("has_watchcommentall", 1);
            echo "<table><tr><td>Send mail for: &nbsp;</td>",
                "<td>", Ht::checkbox("watchcomment", 1, !!get($follow, "reviews"), ["data-default-checked" => !!get($cfollow, "reviews")]), "&nbsp;",
                Ht::label($us->conf->_("Reviews and comments on authored or reviewed papers")), "</td></tr>",
                "<tr><td></td><td>", Ht::checkbox("watchcommentall", 1, !!get($follow, "allreviews"), ["data-default-checked" => !!get($cfollow, "allreviews")]), "&nbsp;",
                Ht::label($us->conf->_("Reviews and comments on <i>any</i> paper")), "</td></tr>";
            if (!$us->user->is_empty() && $us->user->privChair) {
                echo "<tr><td></td><td>", Ht::checkbox("watchfinalall", 1, !!get($follow, "allfinal"), ["data-default-checked" => !!get($cfollow, "allfinal")]), "&nbsp;",
                    Ht::label($us->conf->_("Updates to final versions")),
                    Ht::hidden("has_watchfinalall", 1), "</td></tr>";
            }
            echo "</table>";
        } else
            echo Ht::checkbox("watchcomment", 1, !!get($follow, "reviews"), ["data-default-checked" => !!get($cfollow, "reviews")]), "&nbsp;",
                Ht::label($us->conf->_("Send mail for new comments on authored or reviewed papers"));
    }

    static function render_roles(UserStatus $us, $cj, $reqj, $uf) {
        if (!$us->viewer->privChair)
            return;
        echo '<h3 class="profile">Roles</h3>', "\n",
          "<table><tr><td class=\"nw\">\n";
        $pcrole = self::pcrole_text($reqj);
        $cpcrole = self::pcrole_text($cj);
        foreach (["chair" => "PC chair", "pc" => "PC member",
                  "no" => "Not on the PC"] as $k => $v) {
            echo Ht::radio("pctype", $k, $pcrole === $k, ["class" => "js-role keep-focus", "data-default-checked" => $cpcrole === $k]),
                "&nbsp;", Ht::label($v), "<br />\n";
        }
        Ht::stash_script('$(".js-role").on("change", profile_ui);$(function(){$(".js-role").first().trigger("change")})');

        echo "</td><td><span class='sep'></span></td><td class='nw'>";
        $is_ass = isset($reqj->roles) && get($reqj->roles, "sysadmin");
        $cis_ass = isset($cj->roles) && get($cj->roles, "sysadmin");
        echo Ht::checkbox("ass", 1, $is_ass, ["data-default-checked" => $cis_ass]),
            "&nbsp;</td><td>", Ht::label("Sysadmin"), "<br/>",
            '<div class="hint">Sysadmins and PC chairs have full control over all site operations. Sysadmins need not be members of the PC. There’s always at least one administrator (sysadmin or chair).</div></td></tr></table>', "\n";
    }

    static function render_collaborators(UserStatus $us, $cj, $reqj, $uf) {
        echo '<h3 class="', $us->control_class("collaborators", "profile"), '">Collaborators and other affiliations</h3>', "\n",
            "<div>Please list potential conflicts of interest. We use this information when assigning reviews. ",
            $us->conf->message_html("conflictdef"),
            " <p>List one conflict per line, using parentheses for affiliations.<br />
        Examples: “Ping Yen Zhang (INRIA)”, “University College London”</p></div>
        <textarea name=\"collaborators\" rows=\"5\" cols=\"80\"";
        if (($className = $us->control_class("collaborators")))
            echo ' class="', $className, '"';
        echo ">", htmlspecialchars(get_s($cj, "collaborators")), "</textarea>\n";
    }

    static function render_topics(UserStatus $us, $cj, $reqj, $uf) {
        echo '<div id="topicinterest" class="fx1">',
            '<h3 class="profile">Topic interests</h3>', "\n",
            '<p>Please indicate your interest in reviewing papers on these conference
topics. We use this information to help match papers to reviewers.</p>',
            Ht::hidden("has_ti", 1),
            '  <table class="topicinterest"><thead>
    <tr><td></td><th class="ti_interest">Low</th><th class="ti_interest" style="width:2.2em">-</th><th class="ti_interest" style="width:2.2em">-</th><th class="ti_interest" style="width:2.2em">-</th><th class="ti_interest">High</th></tr></thead><tbody>', "\n";

        $ibound = [-INF, -1.5, -0.5, 0.5, 1.5, INF];
        $reqj_topics = (array) get($reqj, "topics", []);
        foreach ($us->conf->topic_map() as $id => $name) {
            echo "      <tr><td class=\"ti_topic\">", htmlspecialchars($name), "</td>";
            $ival = (float) get($reqj_topics, $id);
            for ($j = -2; $j <= 2; ++$j) {
                $checked = $ival >= $ibound[$j+2] && $ival < $ibound[$j+3];
                echo '<td class="ti_interest">', Ht::radio("ti$id", $j, $checked, ["class" => "js-range-click", "data-range-type" => "topicinterest$j"]), "</td>";
            }
            echo "</tr>\n";
        }
        echo "    </tbody></table></div>\n";
    }

    static function render_tags(UserStatus $us, $cj, $reqj, $uf) {
        if ((!$us->user->isPC || empty($reqj->tags)) && !$us->viewer->privChair)
            return;
        $tags = isset($reqj->tags) && is_array($reqj->tags) ? $reqj->tags : [];
        echo "<div class=\"fx1\"><h3 class=\"profile\">Tags</h3>\n";
        if ($us->viewer->privChair) {
            echo '<div class="', $us->control_class("contactTags", "f-i"), '">',
                Ht::entry("contactTags", join(" ", $tags), ["size" => 60]),
                "</div>
  <div class='hint'>Example: “heavy”. Separate tags by spaces; the “pc” tag is set automatically.<br /><strong>Tip:</strong>&nbsp;Use <a href='", hoturl("settings", "group=tags"), "'>tag colors</a> to highlight subgroups in review lists.</div>\n";
        } else {
            echo join(" ", $tags), "<div class='hint'>Tags represent PC subgroups and are set by administrators.</div>\n";
        }
        echo "</div>\n";
    }

    function render_topic($g, $cj, $reqj) {
        $last_title = null;
        foreach ($this->gxt()->members(strtolower($g)) as $gj) {
            $pc = array_search("pc", Conf::xt_allow_list($gj)) !== false;
            if ($pc && !$this->user->isPC && !$this->viewer->privChair)
                continue;
            if ($pc)
                echo '<div class="fx1">';
            GroupedExtensions::render_heading($gj, $last_title, 3, "profile");
            if (isset($gj->render_callback)) {
                Conf::xt_resolve_require($gj);
                call_user_func($gj->render_callback, $this, $cj, $reqj, $gj);
            }
            if ($pc)
                echo '</div>';
        }
    }
}
