<?php

namespace Controller;

class User extends \Controller {

	protected $_userId;

	public function __construct() {
		$this->_userId = $this->_requireLogin();
	}

	public function index($f3, $params) {
		$f3->reroute("/user");
	}

	public function dashboard($f3, $params) {

		// Add user's group IDs to owner filter
		$owner_ids = array($this->_userId);
		$groups = new \Model\User\Group();
		foreach($groups->find(array("user_id = ?", $this->_userId)) as $r) {
			$owner_ids[] = $r->group_id;
		}
		$owner_ids = implode(",", $owner_ids);

		// Load all open issues
		$issue = new \Model\Issue\Detail();
		$issues = $issue->find(
			array(
				"owner_id IN ($owner_ids) and type_id!=:type AND deleted_date IS NULL AND closed_date IS NULL AND status_closed = 0",
				":type" => $f3->get("issue_type.project"),
			), array(
				"order" => "parent_id ASC, `status` ASC, priority DESC, has_due_date ASC, due_date ASC"
			)
		);
		$f3->set("issues", $issues);

		// Load all projects
		$parent_ids = array();
		foreach ($issues as $i) {
			if($i->parent_id) {
				$parent_ids[] = $i->parent_id;
			}
		}
		if($parent_ids) {
			$parent_ids = implode(",", array_unique($parent_ids));
			$parents = $issue->find(array(
				"id IN ($parent_ids) OR (owner_id = :user AND type_id != :type AND deleted_date IS NULL AND closed_date IS NULL AND status_closed = 0)",
				":user" => $this->_userId,
				":type" => $f3->get("issue_type.project"),
			));
		} else {
			$parents = array();
		}

		// Load all issue statuses used to build the insane associative arrays below
		$status = new \Model\Issue\Status;
		$statuses = $status->find();
		$f3->set("statuses", $statuses);

		// Warning: srslywat this works?
		$orphans = array();
		$projects = array();
		foreach($statuses as $s)
			$orphans[$s->id] = array();
		foreach($parents as $p) {
			$projects[$p->id] = array("project" => $p) + $orphans;
			foreach($statuses as $s)
				$projects[$p->id][$s->id] = array();
			foreach($issues as &$i) {
				if($i->parent_id == $p->id)
					$projects[$p->id][$s->id][$i->id] = $i;
				unset($i);
			}
		}
		foreach($issues as $i) {
			$orphans[$i->status][$i->id] = $i;
		}

		// All good, pass the data on to the view
		$f3->set("orphans", $orphans);
		$f3->set("projects", $projects);

		// Get current sprint if there is one
		$sprint = new \Model\Sprint;
		$sprint->load("NOW() BETWEEN start_date AND end_date");
		$f3->set("sprint", $sprint);

		$f3->set("menuitem", "index");
		$this->_render("user/dashboard.html");
	}

	private function _loadThemes() {
		$f3 = \Base::instance();

		// Get theme list
		$hidden_themes = array("backlog", "style", "taskboard", "datepicker", "jquery-ui-1.10.3", "bootstrap-tagsinput");
		$themes = array();
		foreach (glob("css/*.css") as $file) {
			$name = pathinfo($file, PATHINFO_FILENAME);
			if(!in_array($name, $hidden_themes)) {
				$themes[] = $name;
			}
		}

		$f3->set("themes", $themes);
		return $themes;
	}

	public function account($f3, $params) {
		$f3->set("title", "My Account");
		$f3->set("menuitem", "user");

		$this->_loadThemes();

		$this->_render("user/account.html");
	}

	public function save($f3, $params) {
		$f3 = \Base::instance();
		$post = array_map("trim", $f3->get("POST"));

		$user = new \Model\User();
		$user->load($this->_userId);

		if(!empty($post["old_pass"])) {

			$security = \Helper\Security::instance();

			// Update password
			if($security->hash($post["old_pass"], $user->salt) == $user->password) {
				if(strlen($post["new_pass"]) >= 6) {
					$user->salt = $security->salt();
					$user->password = $security->hash($post["new_pass"], $user->salt);
					$f3->set("success", "Password updated successfully.");
				} else {
					$f3->set("error", "New password must be at least 6 characters.");
				}
			} else {
				$f3->set("error", "Current password entered is not valid.");
			}

		} else {

			// Update profile
			if(!empty($post["name"])) {
				$user->name = filter_var($post["name"], FILTER_SANITIZE_STRING);
			} else {
				$error = "Please enter your name.";
			}
			if(preg_match("/^([\p{L}\.\-\d]+)@([\p{L}\-\.\d]+)((\.(\p{L})+)+)$/im", $post["email"])) {
				$user->email = $post["email"];
			} else {
				$error = $post["email"] . " is not a valid email address.";
			}
			if(empty($error) && ctype_xdigit(ltrim($post["task_color"], "#"))) {
				$user->task_color = ltrim($post["task_color"], "#");
			} elseif(empty($error)) {
				$error = $post["task_color"] . " is not a valid color code.";
			}

			if(empty($post["theme"])) {
				$user->theme = null;
			} else {
				$user->theme = $post["theme"];
			}

			if(empty($error)) {
				$f3->set("success", "Profile updated successfully.");
			} else {
				$f3->set("error", $error);
			}

		}

		$user->save();
		$f3->set("title", "My Account");
		$f3->set("menuitem", "user");

		// Use new user values for page
		$user->loadCurrent();
		$this->_loadThemes();

		$this->_render("user/account.html");
	}

	public function avatar($f3, $params) {
		$f3 = \Base::instance();

		$user = new \Model\User();
		$user->load($this->_userId);
		if(!$user->id) {
			$f3->error(404);
			return;
		}

		$web = \Web::instance();

		$f3->set("UPLOADS",'uploads/avatars/');
		if(!is_dir($f3->get("UPLOADS"))) {
			mkdir($f3->get("UPLOADS"), 0777, true);
		}
		$overwrite = true;
		$slug = true;

		//Make a good name
		$parts = pathinfo($_FILES['avatar']['name']);
		$_FILES['avatar']['name'] = $user->id . "-" . substr(sha1($user->id), 0, 4)  . "." . $parts["extension"];
		$f3->set("avatar_filename", $_FILES['avatar']['name']);

		$web->receive(
			function($file) use($f3, $user) {
				if($file['size'] > $f3->get("files.maxsize")) {
					return false;
				}

				$user->avatar_filename = $f3->get("avatar_filename");
				$user->save();
				return true;
			},
			$overwrite,
			$slug
		);

		// Clear cached profile picture data
		$cache = \Cache::instance();
		$cache->clear($f3->hash("GET /avatar/48/{$user->id}.png") . ".url");
		$cache->clear($f3->hash("GET /avatar/96/{$user->id}.png") . ".url");
		$cache->clear($f3->hash("GET /avatar/128/{$user->id}.png") . ".url");

		$f3->reroute("/user");
	}


	public function single($f3, $params) {
		$this->_requireLogin();

		$user = new \Model\User;
		$user->load(array("username = ? AND deleted_date IS NULL", $params["username"]));

		if($user->id) {
			$f3->set("title", $user->name);
			$f3->set("this_user", $user);

			$issue = new \Model\Issue\Detail;
			$issues = $issue->paginate(0, 100, array("closed_date IS NULL AND deleted_date IS NULL AND (owner_id = ? OR author_id = ?)", $user->id, $user->id));
			$f3->set("issues", $issues);

			$this->_render("user/single.html");
		} else {
			$f3->error(404);
		}
	}

}
