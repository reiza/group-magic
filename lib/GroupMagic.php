<?php

/**
 * GroupMagic - automatic user grouping for Moodle 2.0 enrollment plugins.
 *
 * Automatically adds students to groups
 * Automatically creates groups if needed (Optional)
 * Configurable maximum group size
 * Supports manual group overloading
 * Sequential or balanced auto-filling of groups
 * Supports Moodle's database transaction (Optional)
 *
 * @author OCD Team - Sacha Beharry, Anil Ramnanan, Reiza Haniff
 * @copyright  2011 OCD Team,  {@link http://code.google.com/p/group-magic/}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

class GroupMagic {

	public $maxGroupSize = 1;
	public $groupFillType = 'seq';
	public $createGroups = true;
	public $useDbTransaction = true;
	public $autoGroupRole = false;
	public $ignoreEnrolmentKey = false;

	/* Private methods for wrapping Moodle functions */

	private function _addUserToGroup( $userId, $groupId ) {
		global $CFG;
		require_once( $CFG->dirroot . '/group/lib.php' );
		groups_add_member( $groupId, $userId ); 
		/* group/lib.php */
	}

	private function _getAllCourseGroups( $courseId ) {
		global $CFG;
		require_once( $CFG->libdir . '/grouplib.php' );
		return groups_get_all_groups( $courseId ); 
		/* lib/grouplib.php */
	}

	private function _getGroupMembers( $groupId ) {
		global $CFG;
		require_once( $CFG->libdir . '/grouplib.php' );
		return groups_get_members( $groupId ); 
		/* lib/grouplib.php */
	}

	private function _getUserCourseGroupings( $userId, $courseId ) {
		global $CFG;
		require_once( $CFG->dirroot . '/group/lib.php' );
		return groups_get_user_groups( $courseId, $userId ); 
		/* group/lib.php */
	}

	private function _createNewGroup( $groupObj ) {
		global $CFG;
		require_once( $CFG->dirroot . '/group/lib.php' );
		return groups_create_group( $groupObj ); 
		/* group/lib.php */
	}

	private function _getAllRoles() {
		return get_all_roles();
	}


	/**
	 * Creates a new Group Magic instance
	 * 
	 * @param array $options
	 *
	 * @return void
	 **/
	public function __construct( $options = false ) {
		if( $options ) {
			$this->setOptions( $options );
		}
	}

	/**
	 * Set properties from options array. Very simple for now - used to establish
	 * standard option keys - can be extended with logic or validation in future.
	 *
	 * @param array $options
	 *
	 * @return void
	 */
	public function setOptions( $options ) {

		if( isset( $options['maxGroupSize'] ) ) {
			$this->maxGroupSize = $options['maxGroupSize'];
		}

		if( isset( $options['groupFillType'] ) ) {
			$this->groupFillType = $options['groupFillType'];
		}

		if( isset( $options['createGroups'] ) ) {
			$this->createGroups = $options['createGroups'];
		}

		if( isset( $options['useDbTransaction'] ) ) {
			$this->useDbTransaction = $options['useDbTransaction'];
		}

		if( isset( $options['autoGroupRole'] ) ) {
			$this->autoGroupRole = $options['autoGroupRole'];

			/* To-do: Validate autoGroupRole and move into a new method
			$allroles = $this->_getAllRoles();
			if( !isset( $allroles[$this->autoGroupRole] ) ) {
				$this->autoGroupRole = false;
			}
			*/
		}
	}

	/**
	 * Adds a user to one of a course's groups using best group possible.
	 *
	 * @param int $userId
	 * @param int $courseId
	 * @param array $userRoles
	 *
	 * @return void
	 **/
	public function addUserToCourseGroups( $userId, $courseId, $userRoles ) {
		if( $this->hasAutoGroupRole( $userRoles ) && !$this->isUserInCourseGroup( $userId, $courseId ) ) {
			$bestGroupId = $this->getBestGroupId( $userId, $courseId );
			if( $bestGroupId > 0 ) {
				$this->addUserToGroup( $userId, $bestGroupId );
			}
		}
	}


	/**
	 * Check to see whether any of a user's roles for a given course is the auto-group role.
	 *
	 * @param array $roles
	 *	
	 * @return boolean
	 **/
	public function hasAutoGroupRole( $roles ) {
		if( $this->autoGroupRole === false ) {
			return false;
		}
		return in_array( $this->autoGroupRole, $roles );
	}

	/**
	 * Adds a user to a group. May attempt to use a db transaction.
	 * Moodle will check db and engine for transaction support
	 * If supported appropriate transactions db adapter method call made
	 * Else db actions happen without transaction
	 *
	 * @param int $userId
	 * @param int $groupId
	 *	
	 * @return void
	 **/
	public function addUserToGroup( $userId, $groupId ) {

		if( $this->useDbTransaction ) {
			global $DB;
			try {
				$transaction = $DB->start_delegated_transaction();
				$this->_addUserToGroup( $userId, $groupId );
				$DB->commit_delegated_transaction($transaction);
			} catch(Exception $e) {
				$DB->rollback_delegated_transaction($transaction, $e);
				throw $e;
			}

		} else {
			$this->_addUserToGroup( $userId, $groupId );
		}
	}

	/**
	 * Creates a new course group. Returns the group id.
	 *
	 * @param int $courseId
	 * @param string $groupName
	 *	
	 * @return int
	 **/
	public function createNewCourseGroup( $courseId, $groupName ) {
		$g = new stdClass();
		$g->courseid = $courseId;
		$g->name = $groupName;
		return $this->_createNewGroup( $g );
	}

	/**
	 * Determines best group in a course to add user. May create a new
	 * group if necessary. Returns the group id, or 0 if none found.
	 *
	 * @param int $userId
	 * @param int $courseId
	 *	
	 * @return int
	 **/
	public function getBestGroupId( $userId, $courseId ) {
		$maxSize = $this->maxGroupSize;
		$groups = $this->_getAllCourseGroups( $courseId );
		$groupData = array();
		$bestSeqGroupId = 0;
		$bestSeqGroupSize = -1;
		$bestParaGroupId = 0;
		$bestParaGroupSize = -1;

		foreach( $groups as $group ) {
			$o = new StdClass();
			$o->group = $group;
			$o->users = $this->_getGroupMembers( $group->id );
			$o->size = count( $o->users );
			if( $ignoreEnrolmentKey || !$group->enrolmentkey ) {
				if( $o->size < $maxSize && $o->size > $bestSeqGroupSize ) {
					$bestSeqGroupId = $group->id;
					$bestSeqGroupSize = $o->size;
				}
				if( $o->size < $maxSize && ( $bestParaGroupSize == -1 || $o->size < $bestParaGroupSize ) ) {
					$bestParaGroupId = $group->id;
					$bestParaGroupSize = $o->size;
				}
			}
			$groupData[$group->id] = $o;
		}

		$bestGroupId = 0;
		$fillType = $this->groupFillType;
		if( $fillType == 'seq' ) {
			$bestGroupId = $bestSeqGroupId;
		} else {
			$bestGroupId = $bestParaGroupId;
		}

		$canCreateNewGroups = $this->createGroups;

		// If we are not allowed to create new groups and all are full, then return immediately
		if( $bestGroupId == 0 && !$canCreateNewGroups ) {
			return 0;
		}

		if( $bestGroupId == 0 ) {
			$bestGroupId = $this->createNewCourseGroup( $courseId, 'AUTO-GROUP-' . ( count( $groups ) + 1 ) );
		}
		return $bestGroupId;
	}

	/**
	 * Check to see whether a user is already in any group for a given course. 
	 * Returns number of groups found with user as a member.
	 *
	 * @param int $userId
	 * @param int $courseId
	 *	
	 * @return int
	 **/
	public function isUserInCourseGroup( $courseId, $userId ) {
		$groupings = $this->_getUserCourseGroupings( $userId, $courseId );
		$groupCount = 0;
		foreach( $groupings as $groups ) {
			$groupCount += count( $groups );
		}
		return $groupCount;
	}

}