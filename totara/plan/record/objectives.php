<?php

/**
 * Moodle - Modular Object-Oriented Dynamic Learning Environment
 *          http://moodle.org
 * Copyright (C) 1999 onwards Martin Dougiamas  http://dougiamas.com
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 2 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 *
 * @package    totara
 * @subpackage plan
 * @author     Simon Coggins <simonc@catalyst.net.nz>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL
 * @copyright  (C) 1999 onwards Martin Dougiamas  http://dougiamas.com
 *
 * Displays collaborative features for the current user
 *
 */

    require_once('../../../config.php');
    require_once($CFG->dirroot.'/totara/reportbuilder/lib.php');
    require_once($CFG->dirroot.'/totara/plan/lib.php');

    require_login();

    global $SESSION,$USER;

    $userid     = optional_param('userid', null, PARAM_INT);                       // which user to show
    $format = optional_param('format','', PARAM_TEXT); // export format
    $rolstatus = optional_param('status', 'all', PARAM_ALPHANUM);
    if (!in_array($rolstatus, array('active','completed','all'))) {
        $rolstatus = 'all';
    }

    // default to current user
    if(empty($userid)) {
        $userid = $USER->id;
    }

    if (! $user = get_record('user', 'id', $userid)) {
        error('User not found');
    }

    $context = get_context_instance(CONTEXT_SYSTEM);
    // users can only view their own and their staff's pages
    // or if they are an admin
    if ($USER->id != $userid && !totara_is_manager($userid) && !has_capability('moodle/site:doanything',$context)) {
        error('You cannot view this page');
    }

    $renderer = $PAGE->get_renderer('totara_reportbuilder');

    if ($USER->id != $userid) {
        $strheading = get_string('recordoflearningfor','local').fullname($user, true);
    } else {
        $strheading = get_string('recordoflearning', 'local');
    }
    // get subheading name for display
    $strsubheading = get_string($rolstatus.'objectivessubhead', 'local_plan');

    $shortname = 'plan_objectives';
    $data = array(
        'userid' => $userid,
    );
    if ($rolstatus !== 'all'){
        $data['rolstatus'] = $rolstatus;
    }
    if (!$report = reportbuilder_get_embedded_report($shortname, $data)) {
        print_error('error:couldnotgenerateembeddedreport', 'local_reportbuilder');
    }

    $query_string = !empty($_SERVER['QUERY_STRING']) ? '?'.$_SERVER['QUERY_STRING'] : '';
    $log_url = 'record/objectives.php'.$query_string;

    if ($format != '') {
        add_to_log(SITEID, 'plan', 'record export', $log_url, $report->fullname);
        $report->export_data($format);
        die;
    }

    add_to_log(SITEID, 'plan', 'record view', $log_url, $report->fullname);

    $report->include_js();

    ///
    /// Display the page
    ///

    $navlinks = array();
    $navlinks[] = array('name' => get_string('mylearning', 'local'), 'link' => $CFG->wwwroot . '/my/learning.php', 'type' => 'title');
    $navlinks[] = array('name' => $strheading, 'link' => $CFG->wwwroot . '/totara/plan/record/courses.php', 'type' => 'misc');
    $navlinks[] = array('name' => $strsubheading, 'link' => null, 'type' => 'misc');

    print_header($strheading, $strheading, build_navigation($navlinks));

    $ownplan = $USER->id == $userid;

    $usertype = ($ownplan) ? 'learner' : 'manager';

    echo dp_display_plans_menu($userid, 0, $usertype, 'objectives', $rolstatus);

    print_container_start(false, '', 'dp-plan-content');

    echo '<h1>'.$strheading.' : '.$strsubheading.'</h1>';

    $userstr = (isset($userid)) ? 'userid='.$userid.'&amp;' : '';

    $currenttab = 'objectives';
    require_once($CFG->dirroot . '/totara/plan/record/tabs.php');

    // display table here
    $fullname = $report->fullname;
    $countfiltered = $report->get_filtered_count();
    $countall = $report->get_full_count();

    $heading = $renderer->print_result_count_string($countfiltered, $countall);
    print_heading($heading);

    print $renderer->print_description($report->description, $report->_id);

    $report->display_search();

    if ($countfiltered > 0) {
        print $renderer->showhide_button($report->_id, $report->shortname);
        $report->display_table();
        print $report->edit_button();
        // export button
        $renderer->export_select($report->_id);
    }

    print_container_end();

    print_footer();

?>