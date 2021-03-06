<?php

require_once '../../config.php';
require_once 'lib.php';
require_once 'signup_form.php';

global $DB;

$s = required_param('s', PARAM_INT); // facetoface session ID
$backtoallsessions = optional_param('backtoallsessions', 0, PARAM_INT);

if (!$session = facetoface_get_session($s)) {
    print_error('error:incorrectcoursemodulesession', 'facetoface');
}
if (!$facetoface = $DB->get_record('facetoface', array('id' => $session->facetoface))) {
    print_error('error:incorrectfacetofaceid', 'facetoface');
}
if (!$course = $DB->get_record('course', array('id' => $facetoface->course))) {
    print_error('error:coursemisconfigured', 'facetoface');
}
if (!$cm = get_coursemodule_from_instance("facetoface", $facetoface->id, $course->id)) {
    print_error('error:incorrectcoursemoduleid', 'facetoface');
}

require_course_login($course, true, $cm);
$context = context_course::instance($course->id);
require_capability('mod/facetoface:view', $context);

$returnurl = "$CFG->wwwroot/course/view.php?id=$course->id";
if ($backtoallsessions) {
    $returnurl = "$CFG->wwwroot/mod/facetoface/view.php?f=$backtoallsessions";
}

$pagetitle = format_string($facetoface->name);

$PAGE->set_cm($cm);
$PAGE->set_url('/mod/facetoface/signup.php', array('s' => $s, 'backtoallsessions' => $backtoallsessions));

$PAGE->set_title($pagetitle);
$PAGE->set_heading($course->fullname);

// Guests can't signup for a session, so offer them a choice of logging in or going back.
if (isguestuser()) {
    $loginurl = $CFG->wwwroot.'/login/index.php';
    if (!empty($CFG->loginhttps)) {
        $loginurl = str_replace('http:','https:', $loginurl);
    }

    echo $OUTPUT->header();
    $out = html_writer::tag('p', get_string('guestsno', 'facetoface')) . '\n\n' . html_writer::tag('p', get_string('liketologin', 'facetoface'));
    echo $OUTPUT->confirm($out, $loginurl, get_referer(false));
    echo $OUTPUT->footer();
    exit();
}

$manageremail = false;
$manager = totara_get_manager($USER->id);
if (get_config(NULL, 'facetoface_addchangemanageremail') && !empty($manager)) {
    $manageremail = $manager->email;
}

$showdiscountcode = ($session->discountcost > 0);

$mform = new mod_facetoface_signup_form(null, compact('s', 'backtoallsessions', 'manageremail', 'showdiscountcode'));
if ($mform->is_cancelled()) {
    redirect($returnurl);
}

if ($fromform = $mform->get_data()) { // Form submitted

    if (empty($fromform->submitbutton)) {
        print_error('error:unknownbuttonclicked', 'facetoface', $returnurl);
    }

    // User can not update Manager's email (depreciated functionality)
    if (!empty($fromform->manageremail)) {
        add_to_log($course->id, 'facetoface', 'update manageremail (FAILED)', "signup.php?s=$session->id", $facetoface->id, $cm->id);
    }

    // If multiple sessions are allowed then just check against this session
    // Otherwise check against all sessions
    $multisessionid = ($facetoface->multiplesessions ? $session->id : null);
    if (!facetoface_session_has_capacity($session, $context) && (!$session->allowoverbook)) {
        print_error('sessionisfull', 'facetoface', $returnurl);
    } else if (facetoface_get_user_submissions($facetoface->id, $USER->id, MDL_F2F_STATUS_REQUESTED, MDL_F2F_STATUS_FULLY_ATTENDED, $multisessionid)) {
        print_error('alreadysignedup', 'facetoface', $returnurl);
    } else if (facetoface_manager_needed($facetoface) && empty($manager->email)) {
        print_error('error:manageremailaddressmissing', 'facetoface', $returnurl);
    }

    $params = array();
    $params['discountcode']     = $fromform->discountcode;
    $params['notificationtype'] = $fromform->notificationtype;

    $result = facetoface_user_import($course, $facetoface, $session, $USER->id, $params);
    if ($result['result'] === true) {
        add_to_log($course->id, 'facetoface', 'signup', "signup.php?s=$session->id", $session->id, $cm->id);

        $message = get_string('bookingcompleted', 'facetoface');
        if ($session->datetimeknown
            && isset($facetoface->confirmationinstrmngr)
            && !empty($facetoface->confirmationstrmngr)) {
            $message .= html_writer::empty_tag('br') . html_writer::empty_tag('br') .
                get_string('confirmationsentmgr', 'facetoface');
        } else {
            if ($fromform->notificationtype != MDL_F2F_NONE) {
                $message .= html_writer::empty_tag('br') . html_writer::empty_tag('br') .
                        get_string('confirmationsent', 'facetoface');
            }
        }

        totara_set_notification($message, $returnurl, array('class' => 'notifysuccess'));
    } else {
        if (isset($result['conflict']) && $result['conflict']) {
            totara_set_notification($result['result'], $returnurl);
        } else {
            add_to_log($course->id, 'facetoface', 'signup (FAILED)', "signup.php?s=$session->id", $session->id, $cm->id);
            print_error('error:problemsigningup', 'facetoface', $returnurl);
        }
    }

    redirect($returnurl);
} else if (!empty($manageremail)) {
    // Set values for the form
    $toform = new stdClass();
    $toform->manageremail = $manageremail;
    $mform->set_data($toform);
}

echo $OUTPUT->header();

$heading = get_string('signupfor', 'facetoface', $facetoface->name);

$viewattendees = has_capability('mod/facetoface:viewattendees', $context);
$multisessionid = ($facetoface->multiplesessions ? $session->id : null);
$signedup = facetoface_check_signup($facetoface->id, $multisessionid);
if ($signedup and $signedup != $session->id) {
    print_error('error:signedupinothersession', 'facetoface', $returnurl);
}

echo $OUTPUT->box_start();
echo $OUTPUT->heading($heading);

$timenow = time();

if ($session->datetimeknown && facetoface_has_session_started($session, $timenow)) {
    $inprogress_str = get_string('cannotsignupsessioninprogress', 'facetoface');
    $over_str = get_string('cannotsignupsessionover', 'facetoface');

    $errorstring = facetoface_is_session_in_progress($session, $timenow) ? $inprogress_str : $over_str;

    echo $OUTPUT->notification($errorstring, 'notifyproblem');
    echo $OUTPUT->box_end();
    echo $OUTPUT->footer($course);
    exit();
}

if (!facetoface_session_has_capacity($session, $context, MDL_F2F_STATUS_WAITLISTED) && !$session->allowoverbook) {
    echo $OUTPUT->notification(get_string('sessionisfull', 'facetoface'), 'notifyproblem');
    echo $OUTPUT->box_end();
    echo $OUTPUT->footer($course);
    exit();
}

echo facetoface_print_session($session, $viewattendees);

if ($signedup) {
    if (!($session->datetimeknown && facetoface_has_session_started($session, $timenow))) {
        // Cancellation link.
        echo html_writer::link(new moodle_url('cancelsignup.php', array('s' => $session->id, 'backtoallsessions' => $backtoallsessions)), get_string('cancelbooking', 'facetoface'), array('title' => get_string('cancelbooking', 'facetoface')));
        echo ' &ndash; ';
    }
    // See attendees link.
    if ($viewattendees) {
        echo html_writer::link(new moodle_url('attendees.php', array('s' => $session->id, 'backtoallsessions' => $backtoallsessions)), get_string('seeattendees', 'facetoface'), array('title' => get_string('seeattendees', 'facetoface')));
    }

    echo html_writer::empty_tag('br') . html_writer::link($returnurl, get_string('goback', 'facetoface'), array('title' => get_string('goback', 'facetoface')));
}
// Don't allow signup to proceed if a manager is required.
else if (facetoface_manager_needed($facetoface) && empty($manager->email)) {
    // Check to see if the user has a managers email set.
    echo html_writer::tag('p', html_writer::tag('strong', get_string('error:manageremailaddressmissing', 'facetoface')));
    echo html_writer::empty_tag('br') . html_writer::link($returnurl, get_string('goback', 'facetoface'), array('title' => get_string('goback', 'facetoface')));

} else if (!has_capability('mod/facetoface:signup', $context)) {
    echo html_writer::tag('p', html_writer::tag('strong', get_string('error:nopermissiontosignup', 'facetoface')));
    echo html_writer::empty_tag('br') . html_writer::link($returnurl, get_string('goback', 'facetoface'), array('title' => get_string('goback', 'facetoface')));
} else {
    // Signup form.
    $mform->display();
}

echo $OUTPUT->box_end();
echo $OUTPUT->footer($course);
