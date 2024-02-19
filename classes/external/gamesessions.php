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

namespace mod_philosophers\external;

use core_completion\manager;
use function array_filter;
use function array_map;
use function array_pop;
use function assert;
use coding_exception;
use core\plugininfo\qtype;
use dml_exception;
use external_api;
use external_function_parameters;
use external_single_structure;
use external_value;
use function implode;
use invalid_parameter_exception;
use mod_philosophers\external\exporter\gamesession_dto;
use mod_philosophers\external\exporter\question_dto;
use mod_philosophers\model\gamesession;
use mod_philosophers\model\question;
use mod_philosophers\util;
use moodle_exception;
use question_answer;
use restricted_context_exception;
use function shuffle;
use stdClass;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/question/engine/bank.php');
require_once($CFG->dirroot . '/lib/externallib.php');

/**
 * Class gamesessions
 *
 * @package    mod_philosophers\external
 * @copyright  2019 Benedikt Kulmann <b@kulmann.biz>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class gamesessions extends external_api {

    /**
     * Definition of parameters for {@see create_gamesession}.
     *
     * @return external_function_parameters
     */
    public static function create_gamesession_parameters() {
        return new external_function_parameters([
            'coursemoduleid' => new external_value(PARAM_INT, 'course module id'),
        ]);
    }

    /**
     * Definition of return type for {@see create_gamesession}.
     *
     * @return external_single_structure
     */
    public static function create_gamesession_returns() {
        return gamesession_dto::get_read_structure();
    }

    /**
     * Dumps all previous game sessions of the current user and returns a fresh one.
     *
     * @param int $coursemoduleid
     *
     * @return stdClass
     * @throws dml_exception
     * @throws invalid_parameter_exception
     * @throws moodle_exception
     * @throws restricted_context_exception
     */
    public static function create_gamesession($coursemoduleid) {
        $params = ['coursemoduleid' => $coursemoduleid];
        self::validate_parameters(self::create_gamesession_parameters(), $params);

        list($course, $coursemodule) = get_course_and_cm_from_cmid($coursemoduleid, 'philosophers');
        self::validate_context($coursemodule->context);

        global $PAGE;
        $renderer = $PAGE->get_renderer('core');
        $ctx = $coursemodule->context;
        $game = util::get_game($coursemodule);

        // dump existing game sessions
        util::dump_running_gamesessions($game);

        // create a new one
        $gamesession = util::insert_gamesession($game);

        // return it
        $exporter = new gamesession_dto($gamesession, $game, $ctx);
        return $exporter->export($renderer);
    }

    /**
     * Definition of parameters for {@see cancel_gamesession}.
     *
     * @return external_function_parameters
     */
    public static function cancel_gamesession_parameters() {
        return new external_function_parameters([
            'coursemoduleid' => new external_value(PARAM_INT, 'course module id'),
            'gamesessionid' => new external_value(PARAM_INT, 'game session id'),
        ]);
    }

    /**
     * Definition of return type for {@see cancel_gamesession}.
     *
     * @return external_single_structure
     */
    public static function cancel_gamesession_returns() {
        return gamesession_dto::get_read_structure();
    }

    /**
     * Sets the state of the given game session to DUMPED.
     *
     * @param int $coursemoduleid
     * @param int $gamesessionid
     *
     * @return stdClass
     * @throws coding_exception
     * @throws dml_exception
     * @throws invalid_parameter_exception
     * @throws moodle_exception
     * @throws restricted_context_exception
     */
    public static function cancel_gamesession($coursemoduleid, $gamesessionid) {
        $params = ['coursemoduleid' => $coursemoduleid, 'gamesessionid' => $gamesessionid];
        self::validate_parameters(self::cancel_gamesession_parameters(), $params);

        list($course, $coursemodule) = get_course_and_cm_from_cmid($coursemoduleid, 'philosophers');
        self::validate_context($coursemodule->context);

        global $PAGE;
        $renderer = $PAGE->get_renderer('core');
        $ctx = $coursemodule->context;
        $game = util::get_game($coursemodule);

        // get gamesession by the provided id
        $gamesession = util::get_gamesession($gamesessionid);
        util::validate_gamesession($game, $gamesession);

        // cancel the gamesession
        if ($gamesession->is_in_progress()) {
            $gamesession->set_state(gamesession::STATE_DUMPED);
            $gamesession->save();
        }

        // return the changed game session
        $exporter = new gamesession_dto($gamesession, $game, $ctx);
        return $exporter->export($renderer);
    }

    /**
     * Definition of parameters for {@see get_current_gamesession}.
     *
     * @return external_function_parameters
     */
    public static function get_current_gamesession_parameters() {
        return new external_function_parameters([
            'coursemoduleid' => new external_value(PARAM_INT, 'course module id'),
        ]);
    }

    /**
     * Definition of return type for {@see get_current_gamesession}.
     *
     * @return external_single_structure
     */
    public static function get_current_gamesession_returns() {
        return gamesession_dto::get_read_structure();
    }

    /**
     * Get current gamesession.
     *
     * @param int $coursemoduleid
     *
     * @return stdClass
     * @throws coding_exception
     * @throws dml_exception
     * @throws invalid_parameter_exception
     * @throws moodle_exception
     * @throws restricted_context_exception
     */
    public static function get_current_gamesession($coursemoduleid) {
        $params = ['coursemoduleid' => $coursemoduleid];
        self::validate_parameters(self::get_current_gamesession_parameters(), $params);

        list($course, $coursemodule) = get_course_and_cm_from_cmid($coursemoduleid, 'philosophers');
        self::validate_context($coursemodule->context);

        global $PAGE;
        $renderer = $PAGE->get_renderer('core');
        $ctx = $coursemodule->context;
        $game = util::get_game($coursemodule);

        // try to find existing in-progress gamesession or create a new one
        $gamesession = util::get_or_create_gamesession($game);
        $exporter = new gamesession_dto($gamesession, $game, $ctx);
        return $exporter->export($renderer);
    }

    /**
     * Definition of parameters for {@see get_current_question}.
     *
     * @return external_function_parameters
     */
    public static function get_question_parameters() {
        return new external_function_parameters([
            'coursemoduleid' => new external_value(PARAM_INT, 'course module id'),
            'gamesessionid' => new external_value(PARAM_INT, 'game session id'),
            'levelid' => new external_value(PARAM_INT, 'id of the level'),
        ]);
    }

    /**
     * Definition of return type for {@see get_question}.
     *
     * @return external_single_structure
     */
    public static function get_question_returns() {
        return question_dto::get_read_structure();
    }

    /**
     * Get current question. Selects a new one, if none is currently selected.
     *
     * @param int $coursemoduleid
     * @param int $gamesessionid
     * @param int $levelid
     *
     * @return stdClass
     * @throws dml_exception
     * @throws invalid_parameter_exception
     * @throws moodle_exception
     * @throws restricted_context_exception
     */
    public static function get_question($coursemoduleid, $gamesessionid, $levelid) {
        $params = [
            'coursemoduleid' => $coursemoduleid,
            'gamesessionid' => $gamesessionid,
            'levelid' => $levelid,
        ];
        self::validate_parameters(self::get_question_parameters(), $params);

        list($course, $coursemodule) = get_course_and_cm_from_cmid($coursemoduleid, 'philosophers');
        self::validate_context($coursemodule->context);

        global $PAGE;
        $renderer = $PAGE->get_renderer('core');
        $ctx = $coursemodule->context;
        $game = util::get_game($coursemodule);

        // try to find existing in-progress gamesession or create a new one
        $gamesession = util::get_or_create_gamesession($game);
        util::validate_gamesession($game, $gamesession);

        // grab the requested level
        $level = util::get_level($levelid);

        // get question or create a new one if necessary.
        $question = $gamesession->get_question_by_level($level->get_id());
        if (!$gamesession->is_level_finished($level->get_id()) && $question === null) {
            $question = new question();
            $question->set_gamesession($gamesessionid);
            $question->set_level($level->get_id());
            $mdl_question = $level->get_random_question();
            $question->set_mdl_question($mdl_question->id);
            $mdl_answer_ids = array_map(
                function ($mdl_answer) {
                    return $mdl_answer->id;
                },
                $mdl_question->answers
            );
            if ($game->is_question_shuffle_answers()) {
                shuffle($mdl_answer_ids);
            }
            $question->set_mdl_answers_order(implode(",", $mdl_answer_ids));
            $question->save();
        }

        // return
        $exporter = new question_dto($question, $game, $ctx);
        return $exporter->export($renderer);
    }

    /**
     * Definition of parameters for {@see submit_answer}.
     *
     * @return external_function_parameters
     */
    public static function submit_answer_parameters() {
        return new external_function_parameters([
            'coursemoduleid' => new external_value(PARAM_INT, 'course module id'),
            'gamesessionid' => new external_value(PARAM_INT, 'game session id'),
            'questionid' => new external_value(PARAM_INT, 'question id'),
            'mdlanswerid' => new external_value(PARAM_INT, 'id of the selected moodle answer'),
        ]);
    }

    /**
     * Definition of return type for {@see submit_answer}.
     *
     * @return external_single_structure
     */
    public static function submit_answer_returns() {
        return question_dto::get_read_structure();
    }

    /**
     * Applies the submitted answer to the question record in our DB.
     *
     * @param int $coursemoduleid
     * @param int $gamesessionid
     * @param int $questionid
     * @param int $mdlanswerid
     *
     * @return stdClass
     * @throws dml_exception
     * @throws invalid_parameter_exception
     * @throws moodle_exception
     * @throws restricted_context_exception
     */
    public static function submit_answer($coursemoduleid, $gamesessionid, $questionid, $mdlanswerid) {
        $params = [
            'coursemoduleid' => $coursemoduleid,
            'gamesessionid' => $gamesessionid,
            'questionid' => $questionid,
            'mdlanswerid' => $mdlanswerid,
        ];
        self::validate_parameters(self::submit_answer_parameters(), $params);

        list($course, $coursemodule) = get_course_and_cm_from_cmid($coursemoduleid, 'philosophers');
        self::validate_context($coursemodule->context);

        global $PAGE;
        $renderer = $PAGE->get_renderer('core');
        $ctx = $coursemodule->context;
        $game = util::get_game($coursemodule);

        // try to find existing in-progress gamesession
        $gamesession = util::get_gamesession($gamesessionid);
        if (!$gamesession->is_in_progress()) {
            throw new moodle_exception('gamesession is not available anymore.');
        }
        util::validate_gamesession($game, $gamesession);

        // get the question
        $question = util::get_question($questionid);
        util::validate_question($gamesession, $question);
        if ($question->is_finished()) {
            throw new moodle_exception('question has already been answered.');
        }
        $mdl_question = $question->get_mdl_question_ref();
        if (!property_exists($mdl_question, 'answers')) {
            throw new coding_exception('property »answers« doesn\'t exist on the moodle question with id ' . $question->get_mdl_question() . '.');
        }

        // submit the answer
        $correct_mdl_answers = array_filter(
            $mdl_question->answers,
            function (question_answer $mdlanswer) {
                return $mdlanswer->fraction == 1;
            }
        );
        if (count($correct_mdl_answers) !== 1) {
            throw new moodle_exception('The moodle question with id ' . $question->get_mdl_question() . ' seems to be inapplicable for this activity.');
        }
        $correct_mdl_answer = array_pop($correct_mdl_answers);
        assert($correct_mdl_answer instanceof question_answer);
        $question->set_mdl_answer_given($mdlanswerid);
        $question->set_finished(true);
        $question->set_correct($correct_mdl_answer->id == $mdlanswerid);
        $time_taken = (\time() - $question->get_timecreated());
        $time_available = util::calculate_available_time($game, $question);
        $time_remaining = \max(0, ($time_available - $time_taken));
        $question->set_timeremaining($time_remaining);
        if ($question->is_correct()) {
            $max_points = $game->get_question_duration();
            $points = \min($time_remaining, $max_points);
            $question->set_score($points);
        }
        $question->save();

        // update stats in the gamesession
        $gamesession->increment_answers_total();
        if ($question->is_correct()) {
            $gamesession->increase_score($question->get_score());
            $gamesession->increment_answers_correct();
        }
        if ($gamesession->get_answers_total() === $game->count_active_levels()) {
            $gamesession->set_state(gamesession::STATE_FINISHED);

            // notify completion system about possible completion
            $completion = new \completion_info($course);
            if($completion->is_enabled($coursemodule)) {
                $completion->update_state($coursemodule,COMPLETION_COMPLETE);
            }
        }
        $gamesession->save();

        // return result object
        $exporter = new question_dto($question, $game, $ctx);
        return $exporter->export($renderer);
    }

    /**
     * Definition of parameters for {@see cancel_answer}.
     *
     * @return external_function_parameters
     */
    public static function cancel_answer_parameters() {
        return new external_function_parameters([
            'coursemoduleid' => new external_value(PARAM_INT, 'course module id'),
            'gamesessionid' => new external_value(PARAM_INT, 'game session id'),
            'questionid' => new external_value(PARAM_INT, 'question id'),
        ]);
    }

    /**
     * Definition of return type for {@see cancel_answer}.
     *
     * @return external_single_structure
     */
    public static function cancel_answer_returns() {
        return question_dto::get_read_structure();
    }

    /**
     * Marks the question as cancelled (i.e. time ran out).
     *
     * @param int $coursemoduleid
     * @param int $gamesessionid
     * @param int $questionid
     *
     * @return stdClass
     * @throws dml_exception
     * @throws invalid_parameter_exception
     * @throws moodle_exception
     * @throws restricted_context_exception
     */
    public static function cancel_answer($coursemoduleid, $gamesessionid, $questionid) {
        $params = [
            'coursemoduleid' => $coursemoduleid,
            'gamesessionid' => $gamesessionid,
            'questionid' => $questionid,
        ];
        self::validate_parameters(self::cancel_answer_parameters(), $params);

        list($course, $coursemodule) = get_course_and_cm_from_cmid($coursemoduleid, 'philosophers');
        self::validate_context($coursemodule->context);

        global $PAGE;
        $renderer = $PAGE->get_renderer('core');
        $ctx = $coursemodule->context;
        $game = util::get_game($coursemodule);

        // try to find existing in-progress gamesession
        $gamesession = util::get_gamesession($gamesessionid);
        if (!$gamesession->is_in_progress()) {
            throw new moodle_exception('gamesession is not available anymore.');
        }
        util::validate_gamesession($game, $gamesession);

        // get the question
        $question = util::get_question($questionid);
        util::validate_question($gamesession, $question);
        if ($question->is_finished()) {
            throw new moodle_exception('question has already been answered.');
        }

        // set data on question
        $question->set_mdl_answer_given(0);
        $question->set_finished(true);
        $question->set_correct(false);
        $question->set_score(0);
        $time_taken = (\time() - $question->get_timecreated());
        $time_available = util::calculate_available_time($game, $question);
        $time_remaining = \max(0, ($time_available - $time_taken));
        $question->set_timeremaining($time_remaining);
        $question->save();

        // update stats in the gamesession
        $gamesession->increment_answers_total();
        if ($gamesession->get_answers_total() === $game->count_active_levels()) {
            $gamesession->set_state(gamesession::STATE_FINISHED);
        }
        $gamesession->save();

        // return result object
        $exporter = new question_dto($question, $game, $ctx);
        return $exporter->export($renderer);
    }
}
