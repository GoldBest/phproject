<?php

namespace Controller;

class Admin extends Base {

	protected $_userId;

	public function __construct() {
		$this->_userId = $this->_requireAdmin();
	}

	public function index($f3, $params) {
		$f3->set("title", "Administration");
		$f3->set("menuitem", "admin");

		if($f3->get("POST.action") == "clearcache") {
			\Cache::instance()->reset();
			$f3->set("success", "Cache cleared successfully.");
		}

		// Gather some stats
		$db = $f3->get("db.instance");

		$db->exec("SELECT id FROM user WHERE deleted_date IS NULL AND role != 'group'");
		$f3->set("count_user", $db->count());
		$db->exec("SELECT id FROM issue WHERE deleted_date IS NULL");
		$f3->set("count_issue", $db->count());
		$db->exec("SELECT id FROM issue_update");
		$f3->set("count_issue_update", $db->count());
		$db->exec("SELECT id FROM issue_comment");
		$f3->set("count_issue_comment", $db->count());

		if($f3->get("CACHE") == "apc") {
			$f3->set("apc_stats", apc_cache_info("user", true));
		}

		$f3->set("db_stats", $db->exec("SHOW STATUS WHERE Variable_name LIKE 'Delayed_%' OR Variable_name LIKE 'Table_lock%' OR Variable_name = 'Uptime'"));

		echo \Template::instance()->render("admin/index.html");
	}

	public function users($f3, $params) {
		$f3->set("title", "Manage Users");
		$f3->set("menuitem", "admin");

		$users = new \Model\User();
		$f3->set("users", $users->find("deleted_date IS NULL AND role != 'group'"));

		echo \Template::instance()->render("admin/users.html");
	}

	public function user_edit($f3, $params) {
		$f3->set("title", "Edit User");
		$f3->set("menuitem", "admin");

		$user = new \Model\User();
		$user->load($params["id"]);

		if($user->id) {
			$f3->set("title", "Edit User");
			if($f3->get("POST")) {
				foreach($f3->get("POST") as $i=>$val) {
					if($i == "password" && !empty($val)) {
						$security = \Helper\Security::instance();
						$user->salt = $security->salt();
						$user->password = $security->hash($val, $user->salt);
					} elseif($i == "salt" || $i == "api_key") {
						// don't change the salt or API key
					} elseif($user->$i != $val && $i != "password"){
						$user->$i = $val;
					}
					$user->save();
					$f3->set("success", "User changes saved.");
				}
			}
			$f3->set("this_user", $user);
			echo \Template::instance()->render("admin/users/edit.html");
		} else {
			$f3->error(404, "User does not exist.");
		}

	}

	public function user_new($f3, $params) {
		$f3->set("title", "New User");
		$f3->set("menuitem", "admin");

		if($f3->get("POST")) {
			$user = new \Model\User();
			$user->username = $f3->get("POST.username");
			$user->email = $f3->get("POST.email");
			$user->name = $f3->get("POST.name");
			$security = \Helper\Security::instance();
			$user->salt = $security->salt();
			$user->password = $security->hash($f3->get("POST.password"), $user->salt);
			$user->api_key = $security->salt_sha1();
			$user->role = $f3->get("POST.role");
			$user->task_color = ltrim($f3->get("POST.task_color"), "#");
			$user->created_date = now();
			$user->save();
			if($user->id) {
				$f3->reroute("/admin/users#" . $user->id);
			} else {
				$f3->error(500, "Failed to save user.");
			}
		} else {
			$f3->set("title", "Add User");
			$f3->set("rand_color", sprintf("#%06X", mt_rand(0, 0xFFFFFF)));
			echo \Template::instance()->render("admin/users/new.html");
		}
	}

	public function user_delete($f3, $params) {
		$user = new \Model\User();
		$user->load($params["id"]);
		$user->delete();

		if($f3->get("AJAX")) {
			print_json(array("deleted" => 1));
		} else {
			$f3->reroute("/admin/users");
		}
	}

	public function groups($f3, $params) {
		$f3->set("title", "Manage Groups");
		$f3->set("menuitem", "admin");

		$group = new \Model\User();
		$groups = $group->find("deleted_date IS NULL AND role = 'group'");

		$group_array = array();
		$db = $f3->get("db.instance");
		foreach($groups as $g) {
			$db->exec("SELECT id FROM user_group WHERE group_id = ?", $g["id"]);
			$count = $db->count();
			$group_array[] = array(
				"id" => $g["id"],
				"name" => $g["name"],
				"task_color" => $g["task_color"],
				"count" => $count
			);
		}
		$f3->set("groups", $group_array);

		echo \Template::instance()->render("admin/groups.html");
	}

	public function group_new($f3, $params) {
		$f3->set("title", "New Group");
		$f3->set("menuitem", "admin");

		if($f3->get("POST")) {
			$group = new \Model\User();
			$group->name = $f3->get("POST.name");
			$group->role = "group";
			$group->task_color = sprintf("%06X", mt_rand(0, 0xFFFFFF));
			$group->created_date = now();
			$group->save();
			$f3->reroute("/admin/groups");
		} else {
			$f3->error(405);
		}
	}

	public function group_edit($f3, $params) {
		$f3->set("title", "Edit Group");
		$f3->set("menuitem", "admin");

		$group = new \Model\User();
		$group->load(array("id = ? AND deleted_date IS NULL AND role = 'group'", $params["id"]));
		$f3->set("group", $group);

		$members = new \Model\Custom("user_group_user");
		$f3->set("members", $members->find(array("group_id = ? AND deleted_date IS NULL", $group->id)));

		$users = new \Model\User();
		$f3->set("users", $users->find("deleted_date IS NULL AND role != 'group'", array("order" => "name ASC")));

		echo \Template::instance()->render("admin/groups/edit.html");
	}

	public function group_delete($f3, $params) {
		$group = new \Model\User();
		$group->load($params["id"]);
		$group->delete();
		if($f3->get("AJAX")) {
			print_json(array("deleted" => 1));
		} else {
			$f3->reroute("/admin/groups");
		}
	}

	public function group_ajax($f3, $params) {
		if(!$f3->get("AJAX")) {
			$f3->error(400);
		}

		$group = new \Model\User();
		$group->load(array("id = ? AND deleted_date IS NULL AND role = 'group'", $f3->get("POST.group_id")));

		if(!$group->id) {
			$f3->error(404);
			return;
		}

		switch($f3->get('POST.action')) {
			case "add_member":
				foreach($f3->get("POST.user") as $user_id) {
					$user_group = new \Model\User\Group();
					$user_group->load(array("user_id = ? AND group_id = ?", $user_id, $f3->get("POST.group_id")));
					if(!$user_group->id) {
						$user_group->group_id = $f3->get("POST.group_id");
						$user_group->user_id = $user_id;
						$user_group->save();
					} else {
						// user already in group
					}
				}
				break;
			case "remove_member":
				$user_group = new \Model\User\Group();
				$user_group->load(array("user_id = ? AND group_id = ?", $f3->get("POST.user_id"), $f3->get("POST.group_id")));
				$user_group->delete();
				print_json(array("deleted" => 1));
				break;
			case "change_title":
				$group->name = trim($f3->get("POST.name"));
				$group->save();
				print_json(array("changed" => 1));
				break;
		}
	}

	public function group_setmanager($f3, $params) {
		$db = $f3->get("db.instance");

		$group = new \Model\User();
		$group->load(array("id = ? AND deleted_date IS NULL AND role = 'group'", $params["id"]));

		if(!$group->id) {
			$f3->error(404);
			return;
		}

		// Remove Manager status from all members and set manager status on specified user
		$db->exec("UPDATE user_group SET manager = 0 WHERE group_id = ?", $group->id);
		$db->exec("UPDATE user_group SET manager = 1 WHERE id = ?", $params["user_group_id"]);

		$f3->reroute("/admin/groups/" . $group->id);
	}

	public function attributes($f3, $params) {
		$f3->set("title", "Manage Attributes");
		$f3->set("menuitem", "admin");

		$attributes = new \Model\Attribute();
		$f3->set("attributes", $attributes->find());

		echo \Template::instance()->render("admin/attributes.html");
	}

	public function attribute_new($f3, $params) {
		$f3->set("title", "New Attribute");

		if($post = $f3->get("POST")) {
			if(!empty($post["name"]) && !empty($post["types"])) {
				$attr = new \Model\Attribute();
				$attr->name = trim($post["name"]);
				$attr->type = trim($post["type"]);
				$attr->default = trim($post["default"]);
				$attr->save();
				foreach($post["types"] as $type) {
					// Save types
				}
			} else {
				$f3->set("attribute", $f3->get("POST"));
			}
		}

		echo \Template::instance()->render("admin/attributes/edit.html");
	}

	public function attribute_edit($f3, $params) {
		$f3->set("title", "Edit Attribute");
		$types = new \Model\Issue\Type();
		$f3->set("issue_types", $types->find(null, null, $f3->get("cache_expire.db")));

		$attr = new \Model\Attribute();
		$attr->load($params["id"]);
		$f3->set("attribute", $attr);

		echo \Template::instance()->render("admin/attributes/edit.html");
	}

	public function sprints($f3, $params) {
		$f3->set("title", "Manage Sprints");
		$f3->set("menuitem", "admin");

		$sprints = new \Model\Sprint();
		$f3->set("sprints", $sprints->find());

		echo \Template::instance()->render("admin/sprints.html");
	}

	public function sprint_new($f3, $params) {
		$f3->set("title", "New Sprint");
		$f3->set("menuitem", "admin");

		if($post = $f3->get("POST")) {
			if(empty($post["start_date"]) || empty($post["end_date"])) {
				$f3->set("error", "Start and end date are required");
				echo \Template::instance()->render("admin/sprints/new.html");
				return;
			}

			$start = strtotime($post["start_date"]);
			$end = strtotime($post["end_date"]);

			if($end <= $start) {
				$f3->set("error", "End date must be after start date");
				echo \Template::instance()->render("admin/sprints/new.html");
				return;
			}

			$sprint = new \Model\Sprint();
			$sprint->name = trim($post["name"]);
			$sprint->start_date = date("Y-m-d", $start);
			$sprint->end_date = date("Y-m-d", $end);
			$sprint->save();
			$f3->reroute("/admin/sprints");
			return;
		}

		echo \Template::instance()->render("admin/sprints/new.html");
	}

	//new function here!!!


	public function sprint_edit($f3, $params) {
		$f3->set("title", "Edit Sprint");
		$f3->set("menuitem", "admin");

		$sprint = new \Model\Sprint;
		$sprint->load($params["id"]);
		if(!$sprint->id) {
			$f3->error(404);
			return;
		}

		if($post = $f3->get("POST")) {
			if(empty($post["start_date"]) || empty($post["end_date"])) {
				$f3->set("error", "Start and end date are required");
				echo \Template::instance()->render("admin/sprints/edit.html");
				return;
			}

			$start = strtotime($post["start_date"]);
			$end = strtotime($post["end_date"]);

			if($end <= $start) {
				$f3->set("error", "End date must be after start date");
				echo \Template::instance()->render("admin/sprints/edit.html");
				return;
			}

			$sprint->name = trim($post["name"]);
			$sprint->start_date = date("Y-m-d", $start);
			$sprint->end_date = date("Y-m-d", $end);
			$sprint->save();

			$f3->reroute("/admin/sprints");
			return;
		}
		$f3->set("sprint", $sprint);

		echo \Template::instance()->render("admin/sprints/edit.html");
	}

	public function sprint_breaker($f3, $params) {
		$f3->set("title", "SprintBreaker");
		$f3->set("menuitem", "admin");

		echo \Template::instance()->render("admin/sprints/breaker.html");
	}
}
