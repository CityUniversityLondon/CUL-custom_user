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
 * Lists all the users within the course.
 *
 * @package   local_culcourse_dashboard
 * @copyright 2020 Amanda Doughty
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use local_culcourse_dashboard\output\photoboard_tabs;
use core_user\table\participants_search;

//require_once('../../config.php');
require_once($CFG->dirroot.'/user/lib.php');
require_once($CFG->dirroot.'/course/lib.php');
require_once($CFG->dirroot.'/notes/lib.php');
require_once($CFG->libdir.'/tablelib.php');
require_once($CFG->libdir.'/filelib.php');
require_once($CFG->dirroot.'/enrol/locallib.php');

use core_table\local\filter\filter;
use core_table\local\filter\integer_filter;
use core_table\local\filter\string_filter;

define('DEFAULT_PAGE_SIZE', 10);
define('SHOW_ALL_PAGE_SIZE', 5000);

$page         = optional_param('page', 0, PARAM_INT); // Which page to show.
$perpage      = optional_param('perpage', DEFAULT_PAGE_SIZE, PARAM_INT); // How many per page.
$contextid    = optional_param('contextid', 0, PARAM_INT); // One of this or.
$courseid     = optional_param('id', 0, PARAM_INT); // This are required.
$roleid       = optional_param('roleid', 0, PARAM_INT);
$urlgroupid   = optional_param('group', 0, PARAM_INT);

$PAGE->set_url('/local/culcourse_dashboard/photoboard.php', array(
        'page' => $page,
        'perpage' => $perpage,
        'contextid' => $contextid,
        'id' => $courseid));

if ($contextid) {
    $context = context::instance_by_id($contextid, MUST_EXIST);
    if ($context->contextlevel != CONTEXT_COURSE) {
        print_error('invalidcontext');
    }
    $course = $DB->get_record('course', array('id' => $context->instanceid), '*', MUST_EXIST);
} else {
    $course = $DB->get_record('course', array('id' => $courseid), '*', MUST_EXIST);
    $context = context_course::instance($course->id, MUST_EXIST);
}

// Not needed anymore.
unset($contextid);
unset($courseid);

require_login($course);

$PAGE->set_pagelayout('incourse');
course_require_view_participants($context);

// Trigger events.
user_list_view($course, $context); // TODO.

$PAGE->set_title("$course->shortname: ".get_string('participants'));
$PAGE->set_heading($course->fullname);
$PAGE->set_pagetype('course-view-' . $course->format);
$PAGE->add_body_class('path-format-culcourse-photos'); // So we can style it independently.
$PAGE->set_other_editing_capability('moodle/course:manageactivities');

echo $OUTPUT->header();

$filterset = new \local_culcourse_dashboard\table\participants_filterset();
$filterset->add_filter(new integer_filter('courseid', filter::JOINTYPE_DEFAULT, [(int)$course->id]));
$participanttable = new \local_culcourse_dashboard\table\participants("local-culcourse-dashboard-photoboard-{$course->id}");
$canaccessallgroups = has_capability('moodle/site:accessallgroups', $context);
$filtergroupids = $urlgroupid ? [$urlgroupid] : [];

// Force group filtering if user should only see a subset of groups' users.
if ($course->groupmode != NOGROUPS && !$canaccessallgroups) {
    if ($filtergroupids) {
        $filtergroupids = array_intersect(
            $filtergroupids,
            array_keys(groups_get_all_groups($course->id, $USER->id))
        );
    } else {
        $filtergroupids = array_keys(groups_get_all_groups($course->id, $USER->id));
    }

    if (empty($filtergroupids)) {
        if ($course->groupmode == SEPARATEGROUPS) {
            // The user is not in a group so show message and exit.
            echo $OUTPUT->notification(get_string('notingroup'));
            echo $OUTPUT->footer();
            exit();
        } else {
            $filtergroupids = [(int) groups_get_course_group($course, true)];
        }
    }
}

// Apply groups filter if included in URL or forced due to lack of capabilities.
if (!empty($filtergroupids)) {
    $filterset->add_filter(new integer_filter('groups', filter::JOINTYPE_DEFAULT, $filtergroupids));
}

// Display single group information if requested in the URL.
if ($urlgroupid > 0 && ($course->groupmode != SEPARATEGROUPS || $canaccessallgroups)) {
    $grouprenderer = $PAGE->get_renderer('core_group');
    $groupdetailpage = new \core_group\output\group_details($urlgroupid);
    echo $grouprenderer->group_details($groupdetailpage);
}


if (has_capability('local/culcourse_dashboard:viewallphotoboard', $context)) {
    // Should use this variable so that we don't break stuff every time a variable
    // is added or changed.
    $baseurl = new moodle_url('/local/culcourse_dashboard/photoboard.php', array(
        'contextid' => $context->id,
        'id' => $course->id,
        'perpage' => $perpage
    ));

    // Filter by role if passed via URL (used on profile page).
    if ($roleid) {
        $viewableroles = get_profile_roles($context);

        // Apply filter if the user can view this role.
        if (array_key_exists($roleid, $viewableroles)) {
            $filterset->add_filter(new integer_filter('roles', filter::JOINTYPE_DEFAULT, [$roleid]));
        }
    }
} else {
    // Need to include fixed roleid for students as they cannot access roles in the
    // unified filter.
    $baseurl = new moodle_url('/local/culcourse_dashboard/photoboard.php', array(
        'contextid' => $context->id,
        'id' => $course->id,
        'perpage' => $perpage,
        'roleid' => $roleid)
    );

    if ($roleid) {
        $photoboardroles = explode(',', $CFG->profileroles);

        // Check if the user can view this role.
        if (in_array($roleid, $photoboardroles)) {
            $where = "(udistinct.id IN (
                         SELECT userid
                           FROM {role_assignments}
                          WHERE roleid = :roleid
                            AND contextid = :contextid)
                       )";

            $conditions = [$where];
            $params = ['roleid' => $roleid, 'contextid' => $context->id];
            $participanttable->set_conditions($conditions, $params);

        } else {
            print_error('invalidrequest');
        }
    } else {
        print_error('invalidrequest');
    }
}

// Do this so we can get the total number of participants.
$prefilterset = new \local_culcourse_dashboard\table\participants_filterset();
$participantssearch = new participants_search($course, $context, $prefilterset);
$grandtotal = $participantssearch->get_total_participants_count();

// Do this so we can get the total number of rows.
ob_start();
$participanttable->set_filterset($filterset);
$participanttable->define_baseurl($baseurl);
$participanttable->out($perpage, true);
$participanttablehtml = ob_get_contents();
ob_end_clean();

// Render the heading.
echo $OUTPUT->heading(
    get_string('matched', 'local_culcourse_dashboard') .
    get_string('labelsep', 'langconfig') .
    '<span data-region="photoboard-count">' .
    $participanttable->totalrows . '</span>/' .
    $grandtotal, 3
);

$pagingbar = null;

// Render the page tabs.
$photoboardtabs = new photoboard_tabs();
$templatecontext = $photoboardtabs->export_for_template($OUTPUT);

echo $OUTPUT->render_from_template('local_culcourse_dashboard/photoboard_tabs', $templatecontext);

$renderable = new \local_culcourse_dashboard\output\participants_filter($context, $participanttable->uniqueid);
$templatecontext = $renderable->export_for_template($OUTPUT);

if ($templatecontext->filtertypes) {
    echo $OUTPUT->render_from_template('core_user/participantsfilter', $templatecontext);
}

echo html_writer::start_tag('form', [
    'method' => 'post',
    'id' => 'participantsform',
    'data-course-id' => $course->id,
    'data-table-unique-id' => $participanttable->uniqueid,
    'data-table-default-per-page' => ($perpage < DEFAULT_PAGE_SIZE) ? $perpage : DEFAULT_PAGE_SIZE,
]);
echo '<div>';
echo '<input type="hidden" name="sesskey" value="'.sesskey().'" />';
echo '<input type="hidden" name="returnto" value="'.s($PAGE->url->out(false)).'" />';

echo html_writer::tag(
    'p',
    get_string('countparticipantsfound', 'core_user', $participanttable->totalrows),
    [
        'data-region' => 'participant-count',
        'class' => 'hidden'
    ]
);

echo $participanttablehtml;

$perpageurl = new moodle_url('/local/culcourse_dashboard/photoboard.php', [
    'contextid' => $context->id,
    'id' => $course->id,
]);

$perpagesize = DEFAULT_PAGE_SIZE;
$perpagevisible = false;
$perpagestring = '';

if ($perpage == SHOW_ALL_PAGE_SIZE && $participanttable->totalrows > DEFAULT_PAGE_SIZE) {
    $perpageurl->param('perpage', $participanttable->totalrows);
    $perpagesize = SHOW_ALL_PAGE_SIZE;
    $perpagevisible = true;
    $perpagestring = get_string('showperpage', '', DEFAULT_PAGE_SIZE);
} else if ($participanttable->get_page_size() < $participanttable->totalrows) {
    $perpageurl->param('perpage', SHOW_ALL_PAGE_SIZE);
    $perpagesize = SHOW_ALL_PAGE_SIZE;
    $perpagevisible = true;
    $perpagestring = get_string('showall', '', $participanttable->totalrows);
}

$perpageclasses = '';
if (!$perpagevisible) {
    $perpageclasses = 'hidden';
}
echo $OUTPUT->container(html_writer::link(
    $perpageurl,
    $perpagestring,
    [
        'data-action' => 'showcount',
        'data-target-page-size' => $perpagesize,
        'class' => $perpageclasses,
    ]
), [], 'showall');

$bulkoptions = (object) [
    'uniqueid' => $participanttable->uniqueid,
];

echo '</form>';

$PAGE->requires->js_call_amd('core_user/participants', 'init', [$bulkoptions]);

echo $OUTPUT->footer();
exit;
