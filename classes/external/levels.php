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

use coding_exception;
use dml_exception;
use function end;
use external_api;
use external_function_parameters;
use external_multiple_structure;
use external_single_structure;
use external_value;
use invalid_parameter_exception;
use mod_philosophers\external\exporter\bool_dto;
use mod_philosophers\external\exporter\category_dto;
use mod_philosophers\external\exporter\level_dto;
use mod_philosophers\model\category;
use mod_philosophers\model\level;
use mod_philosophers\model\question;
use mod_philosophers\util;
use moodle_exception;
use restricted_context_exception;
use stdClass;

defined('MOODLE_INTERNAL') || die();
global $CFG;
require_once($CFG->dirroot . '/lib/externallib.php');

/**
 * Class levels
 *
 * @package    mod_philosophers\external
 * @copyright  2019 Benedikt Kulmann <b@kulmann.biz>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class levels extends external_api {

    /**
     * Definition of parameters for {@see get_levels}.
     *
     * @return external_function_parameters
     */
    public static function get_levels_parameters() {
        return new external_function_parameters([
            'coursemoduleid' => new external_value(PARAM_INT, 'course module id'),
            'gamesessionid' => new external_value(PARAM_INT, 'the id of the current game session, if question information should be added', false)
        ]);
    }

    /**
     * Definition of return type for {@see get_levels}.
     *
     * @return external_multiple_structure
     */
    public static function get_levels_returns() {
        return new external_multiple_structure(
            level_dto::get_read_structure()
        );
    }

    /**
     * Get all levels.
     *
     * @param int $coursemoduleid
     * @param int $gamesessionid The id of the current game session, if question information should be added.
     *
     * @return array
     * @throws coding_exception
     * @throws dml_exception
     * @throws invalid_parameter_exception
     * @throws moodle_exception
     * @throws restricted_context_exception
     */
    public static function get_levels($coursemoduleid, $gamesessionid = 0) {
        $params = ['coursemoduleid' => $coursemoduleid, 'gamesessionid' => $gamesessionid];
        self::validate_parameters(self::get_levels_parameters(), $params);

        list($course, $coursemodule) = get_course_and_cm_from_cmid($coursemoduleid, 'philosophers');
        self::validate_context($coursemodule->context);

        global $PAGE;
        $renderer = $PAGE->get_renderer('core');
        $ctx = $coursemodule->context;
        $game = util::get_game($coursemodule);

        // try to get gamesession - only if it exists! don't create one here!
        if ($gamesessionid > 0) {
            $gamesession = util::get_gamesession($gamesessionid);
            util::validate_gamesession($game, $gamesession);
        } else {
            $gamesession = null;
        }

        // get active levels from DB
        $levels = $game->get_active_levels();

        // sort levels by the saved fixed order (if there is a gamesession)
        if ($gamesession !== null) {
            $level_ids = \explode(',', $gamesession->get_levels_order());
            $sorted_levels = [];
            foreach ($level_ids as $level_id) {
                $level = \reset(\array_filter($levels, function(level $level) use ($level_id) {
                    return $level_id == $level->get_id();
                }));
                if ($level) {
                    $sorted_levels[] = $level;
                }
            }
            $levels = $sorted_levels;
        }

        // collect all already answered questions (if there is a gamesession)
        $questions_by_position = [];
        if ($gamesession !== null) {
            foreach ($levels as $level) {
                \assert($level instanceof level);
                $question = $gamesession->get_question_by_level($level->get_id());
                if ($question !== null) {
                    $questions_by_position[$level->get_position()] = $question;
                }
            }
        }

        // collect export data from levels
        $result = [];
        foreach ($levels as $level_data) {
            $level = new level();
            $level->apply($level_data);
            $question = isset($questions_by_position[$level->get_position()]) ? $questions_by_position[$level->get_position()] : null;
            $exporter = new level_dto($level, $question, $game, $ctx);
            $result[] = $exporter->export($renderer);
        }
        return $result;
    }

    /**
     * Definition of parameters for {@see get_level_categories}.
     *
     * @return external_function_parameters
     */
    public static function get_level_categories_parameters() {
        return new external_function_parameters([
            'coursemoduleid' => new external_value(PARAM_INT, 'course module id'),
            'levelid' => new external_value(PARAM_INT, 'the id of the level to get categories for')
        ]);
    }

    /**
     * Definition of return type for {@see get_level_categories}.
     *
     * @return external_multiple_structure
     */
    public static function get_level_categories_returns() {
        return new external_multiple_structure(
            category_dto::get_read_structure()
        );
    }

    /**
     * Gets all categories for a specific level.
     *
     * @param int $coursemoduleid
     * @param int $levelid
     *
     * @return array
     * @throws \required_capability_exception
     * @throws coding_exception
     * @throws dml_exception
     * @throws invalid_parameter_exception
     * @throws moodle_exception
     * @throws restricted_context_exception
     */
    public static function get_level_categories($coursemoduleid, $levelid) {
        $params = ['coursemoduleid' => $coursemoduleid, 'levelid' => $levelid];
        self::validate_parameters(self::get_level_categories_parameters(), $params);

        list($course, $coursemodule) = get_course_and_cm_from_cmid($coursemoduleid, 'philosophers');
        self::validate_context($coursemodule->context);

        global $PAGE;
        $renderer = $PAGE->get_renderer('core');
        $ctx = $coursemodule->context;
        $game = util::get_game($coursemodule);
        util::require_user_has_capability('mod/philosophers:manage', $ctx);

        // get the level and it's categories
        $level = util::get_level($levelid);
        util::validate_level($game, $level);
        $categories = $level->get_categories();

        // construct the result
        $result = [];
        foreach ($categories as $category) {
            $exporter = new category_dto($category, $ctx);
            $result[] = $exporter->export($renderer);
        }
        return $result;
    }

    /**
     * Definition of parameters for {@see set_level_position}.
     *
     * @return external_function_parameters
     */
    public static function set_level_position_parameters() {
        return new external_function_parameters([
            'coursemoduleid' => new external_value(PARAM_INT, 'course module id'),
            'levelid' => new external_value(PARAM_INT, 'the id of the level which needs to switch its position'),
            'delta' => new external_value(PARAM_INT, 'the direction of the position change. must be either 1 or -1.')
        ]);
    }

    /**
     * Definition of return type for {@see set_level_position}.
     *
     * @return external_single_structure
     */
    public static function set_level_position_returns() {
        return bool_dto::get_read_structure();
    }

    /**
     * Switch the position of the given $levelid with the level before or after it.
     *
     * @param int $coursemoduleid
     * @param int $levelid
     * @param int $delta
     *
     * @return stdClass bool_dto with value true if successful, false if change not necessary. If something goes wrong, an exception will be thrown.
     * @throws dml_exception
     * @throws invalid_parameter_exception
     * @throws moodle_exception
     * @throws restricted_context_exception
     */
    public static function set_level_position($coursemoduleid, $levelid, $delta) {
        $params = ['coursemoduleid' => $coursemoduleid, 'levelid' => $levelid, 'delta' => $delta];
        self::validate_parameters(self::set_level_position_parameters(), $params);

        list($course, $coursemodule) = get_course_and_cm_from_cmid($coursemoduleid, 'philosophers');
        self::validate_context($coursemodule->context);

        global $PAGE;
        $renderer = $PAGE->get_renderer('core');
        $ctx = $coursemodule->context;
        $game = util::get_game($coursemodule);
        util::require_user_has_capability('mod/philosophers:manage', $ctx);

        // validate $delta value
        if (!\in_array($delta, [1, -1])) {
            throw new invalid_parameter_exception("delta value is invalid (is $delta but must be out of [1, -1])");
        }

        // get the levels
        $level = util::get_level($levelid);
        util::validate_level($game, $level);
        $level_other = $game->get_active_level_by_position($level->get_position() + $delta);
        if ($level_other === null) {
            $exporter = new bool_dto(false, $ctx);
            return $exporter->export($renderer);
        } else {
            util::validate_level($game, $level_other);
        }

        // switch them
        $pos = $level->get_position();
        $pos_other = $level_other->get_position();
        $level->set_position($pos_other);
        $level->save();
        $level_other->set_position($pos);
        $level_other->save();

        // return success status
        $exporter = new bool_dto(true, $ctx);
        return $exporter->export($renderer);
    }

    /**
     * Definition of parameters for {@see delete_level}.
     *
     * @return external_function_parameters
     */
    public static function delete_level_parameters() {
        return new external_function_parameters([
            'coursemoduleid' => new external_value(PARAM_INT, 'course module id'),
            'levelid' => new external_value(PARAM_INT, 'the id of the level which is to be deleted'),
        ]);
    }

    /**
     * Definition of return type for {@see delete_level}.
     *
     * @return external_single_structure
     */
    public static function delete_level_returns() {
        return bool_dto::get_read_structure();
    }

    /**
     * Deletes the given level.
     *
     * @param int $coursemoduleid
     * @param int $levelid
     *
     * @return stdClass
     * @throws \required_capability_exception
     * @throws coding_exception
     * @throws dml_exception
     * @throws invalid_parameter_exception
     * @throws moodle_exception
     * @throws restricted_context_exception
     */
    public static function delete_level($coursemoduleid, $levelid) {
        $params = ['coursemoduleid' => $coursemoduleid, 'levelid' => $levelid];
        self::validate_parameters(self::delete_level_parameters(), $params);

        list($course, $coursemodule) = get_course_and_cm_from_cmid($coursemoduleid, 'philosophers');
        self::validate_context($coursemodule->context);

        global $PAGE;
        $renderer = $PAGE->get_renderer('core');
        $ctx = $coursemodule->context;
        $game = util::get_game($coursemodule);
        util::require_user_has_capability('mod/philosophers:manage', $ctx);

        // get the level for validation
        $level = util::get_level($levelid);
        util::validate_level($game, $level);

        // delete it
        $level->set_state(level::STATE_DELETED);
        $level->save();

        // fix positions of higher levels
        $game->fix_level_positions();

        // return success status
        $exporter = new bool_dto(true, $ctx);
        return $exporter->export($renderer);
    }

    /**
     * Definition of parameters for {@see save_level}.
     *
     * @return external_function_parameters
     */
    public static function save_level_parameters() {
        return new external_function_parameters([
            'coursemoduleid' => new external_value(PARAM_INT, 'course module id'),
            'levelid' => new external_value(PARAM_INT, 'the id of the level'),
            'name' => new external_value(PARAM_TEXT, 'name of the level'),
            'bgcolor' => new external_value(PARAM_TEXT, 'the background color for level representation'),
            'categories' => new external_multiple_structure(new external_single_structure([
                'categoryid' => new external_value(PARAM_INT, 'the id in our category db table'),
                'mdlcategory' => new external_value(PARAM_INT, 'the moodle category id'),
                'subcategories' => new external_value(PARAM_BOOL, 'whether or not subcategories should be included'),
            ])),
            'image' => new external_value(PARAM_TEXT, 'image filename'),
            'imgmimetype' => new external_value(PARAM_TEXT, 'image mimetype', false),
            'imgcontent' => new external_value(PARAM_TEXT, 'image content as base64 string', false),
        ]);
    }

    /**
     * Definition of return type for {@see save_level}.
     *
     * @return external_single_structure
     */
    public static function save_level_returns() {
        return bool_dto::get_read_structure();
    }

    /**
     * Updates or inserts the given data as a level and saves the associated categories.
     *
     * @param int $coursemoduleid
     * @param int $levelid
     * @param string $name
     * @param string $bgcolor
     * @param array $categories
     * @param string $image
     * @param string $imgmimetype
     * @param string $imgcontent
     *
     * @return stdClass
     * @throws \required_capability_exception
     * @throws coding_exception
     * @throws dml_exception
     * @throws invalid_parameter_exception
     * @throws moodle_exception
     * @throws restricted_context_exception
     */
    public static function save_level($coursemoduleid, $levelid, $name, $bgcolor, $categories, $image, $imgmimetype, $imgcontent) {
        $params = [
            'coursemoduleid' => $coursemoduleid,
            'levelid' => $levelid,
            'name' => $name,
            'bgcolor' => $bgcolor,
            'categories' => $categories,
            'image' => $image,
            'imgmimetype' => $imgmimetype,
            'imgcontent' => $imgcontent,
        ];
        self::validate_parameters(self::save_level_parameters(), $params);

        list($course, $coursemodule) = get_course_and_cm_from_cmid($coursemoduleid, 'philosophers');
        self::validate_context($coursemodule->context);

        global $PAGE;
        $renderer = $PAGE->get_renderer('core');
        $ctx = $coursemodule->context;
        $game = util::get_game($coursemodule);
        util::require_user_has_capability('mod/philosophers:manage', $ctx);

        // get the level or create one
        if ($levelid) {
            $level = util::get_level($levelid);
            util::validate_level($game, $level);
        } else {
            $level = new level();
            $level->set_game($game->get_id());
        }

        // set the data for the level
        if ($levelid === 0) {
            $level->set_position($game->count_active_levels());
        }
        $level->set_name($name);
        $level->set_bgcolor($bgcolor);
        $level->save();

        // image was cleared in UI. delete it
        if (!empty($level->get_image()) && empty($image)) {
            $level->delete_image($coursemodule->context);
            $level->save();
        }
        // save image if provided in UI (we need an entry id for that, so do that after initial save)
        if ($imgcontent) {
            $level->store_image($coursemodule->context, $imgmimetype, $imgcontent);
            $level->save();
        }

        // transform provided $categories into category model instances.
        $categories = \array_map(function ($category) use ($level) {
            $item = new category();
            $item->set_id(\intval($category['categoryid']));
            $item->set_level($level->get_id());
            $item->set_mdl_category(\intval($category['mdlcategory']));
            $item->set_includes_subcategories(\boolval($category['subcategories']));
            return $item;
        }, $categories);

        // save the categories
        $existing_categories = $level->get_categories();
        $deleted_categories = \array_filter($existing_categories, function (category $existing_category) use ($categories) {
            $found = \array_filter($categories, function (category $cat) use ($existing_category) {
                return $cat->get_id() === $existing_category->get_id();
            });
            return empty($found);
        });
        foreach ($deleted_categories as $category) {
            \assert($category instanceof category);
            $category->delete();
        }
        foreach ($categories as $category) {
            \assert($category instanceof category);
            $category->save();
        }

        // return success status
        $exporter = new bool_dto(true, $ctx);
        return $exporter->export($renderer);
    }
}
