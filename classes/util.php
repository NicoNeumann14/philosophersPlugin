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

namespace mod_philosophers;

use cm_info;
use dml_exception;
use invalid_parameter_exception;
use mod_philosophers\model\game;
use mod_philosophers\model\gamesession;
use mod_philosophers\model\level;
use mod_philosophers\model\question;

class util {

    /**
     * Checks if the logged in user has the given $capability.
     *
     * @param string $capability
     * @param \context $context
     * @param int|null $userid
     *
     * @return bool
     * @throws \coding_exception
     */
    public static function user_has_capability(string $capability, \context $context, $userid = null): bool {
        return \has_capability($capability, $context, $userid);
    }

    /**
     * Kills the current request if the logged in user doesn't have the required capabilities.
     *
     * @param string $capability
     * @param \context $context
     * @param int|null $userid
     *
     * @return void
     * @throws \required_capability_exception
     */
    public static function require_user_has_capability(string $capability, \context $context, $userid = null) {
        \require_capability($capability, $context, $userid);
    }

    /**
     * Checks that the gamesession belongs to the given $game and the logged in $USER.
     *
     * @param game $game
     * @param gamesession $gamesession
     *
     * @return void
     * @throws invalid_parameter_exception
     */
    public static function validate_gamesession(game $game, gamesession $gamesession) {
        if ($game->get_id() !== $gamesession->get_game()) {
            throw new invalid_parameter_exception("gamesession " . $gamesession->get_id() . " doesn't belong to game " . $game->get_id());
        }
        global $USER;
        if ($gamesession->get_mdl_user() != $USER->id) {
            throw new invalid_parameter_exception("gamesession " . $gamesession->get_id() . " doesn't belong to logged in user");
        }
    }

    /**
     * Checks that the question belongs to the given $gamesession.
     *
     * @param gamesession $gamesession
     * @param question $question
     *
     * @return void
     * @throws invalid_parameter_exception
     */
    public static function validate_question(gamesession $gamesession, question $question) {
        if ($gamesession->get_id() !== $question->get_gamesession()) {
            throw new invalid_parameter_exception("question " . $question->get_id() . " doesn't belong to given gamesession");
        }
    }

    /**
     * Checks that the level belongs to the given $game.
     *
     * @param game $game
     * @param level $level
     *
     * @return void
     * @throws invalid_parameter_exception
     */
    public static function validate_level(game $game, level $level) {
        if ($game->get_id() !== $level->get_game()) {
            throw new invalid_parameter_exception("level " . $level->get_id() . " doesn't belong to given game");
        }
    }

    /**
     * Gets the game instance from the database.
     *
     * @param cm_info $coursemodule
     *
     * @return game
     * @throws dml_exception
     */
    public static function get_game(cm_info $coursemodule): game {
        $game = new game();
        $game->load_data_by_id($coursemodule->instance);
        return $game;
    }

    /**
     * Gets the gamesession instance for the given $gamesessionid from the database.
     *
     * @param int $gamesessionid
     *
     * @return gamesession
     * @throws dml_exception
     */
    public static function get_gamesession($gamesessionid): gamesession {
        $gamesession = new gamesession();
        $gamesession->load_data_by_id($gamesessionid);
        return $gamesession;
    }

    /**
     * Loads a level by its id.
     *
     * @param int $levelid
     *
     * @return level
     * @throws dml_exception
     */
    public static function get_level($levelid): level {
        $level = new level();
        $level->load_data_by_id($levelid);
        return $level;
    }

    /**
     * Loads a question by its id.
     *
     * @param int $questionid
     *
     * @return question
     * @throws dml_exception
     */
    public static function get_question($questionid): question {
        $question = new question();
        $question->load_data_by_id($questionid);
        return $question;
    }

    /**
     * Gets or creates a gamesession for the current user. Allowed existing gamesessions are either in state
     * PROGRESS or FINISHED.
     *
     * @param game $game
     *
     * @return gamesession
     * @throws dml_exception
     */
    public static function get_or_create_gamesession(game $game): gamesession {
        global $DB, $USER;
        // try to find existing in-progress or finished gamesession
        $sql = "
            SELECT *
              FROM {philosophers_gamesessions}
             WHERE game = :game AND mdl_user = :mdl_user AND state IN (:state_progress, :state_finished)
          ORDER BY timemodified DESC
        ";
        $params = [
            'game' => $game->get_id(),
            'mdl_user' => $USER->id,
            'state_progress' => gamesession::STATE_PROGRESS,
            'state_finished' => gamesession::STATE_FINISHED,
        ];
        $record = $DB->get_record_sql($sql, $params);
        // get or create game session
        if ($record === false) {
            $gamesession = self::insert_gamesession($game);
        } else {
            $gamesession = new gamesession();
            $gamesession->apply($record);
        }
        return $gamesession;
    }

    /**
     * Closes all game sessions of the current user, which are in state 'progress'.
     *
     * @param game $game
     *
     * @return void
     * @throws dml_exception
     */
    public static function dump_running_gamesessions(game $game) {
        global $DB, $USER;
        $conditions = [
            'game' => $game->get_id(),
            'mdl_user' => $USER->id,
            'state' => gamesession::STATE_PROGRESS,
        ];
        $gamesession = new gamesession();
        $DB->set_field($gamesession->get_table_name(), 'state', $gamesession::STATE_DUMPED, $conditions);
    }

    /**
     * Inserts a new game session into the DB (for the current user).
     *
     * @param game $game
     *
     * @return gamesession
     * @throws dml_exception
     */
    public static function insert_gamesession(game $game): gamesession {
        global $USER;
        $gamesession = new gamesession();
        $gamesession->set_game($game->get_id());
        $gamesession->set_mdl_user($USER->id);
        $level_ids = \array_map(function (level $level) {
            return $level->get_id();
        }, $game->get_active_levels());
        if ($game->is_shuffle_levels()) {
            \shuffle($level_ids);
        }
        $gamesession->set_levels_order(implode(',', $level_ids));
        $gamesession->save();
        return $gamesession;
    }

    /**
     * Calculates the time a user has for answering the given $question.
     *
     * @param game $game
     * @param question $question
     *
     * @return int
     */
    public static function calculate_available_time($game, $question): int {
        return $game->get_question_duration() + self::calculate_reading_time($game, $question);
    }

    /**
     * Calculates the additional time a user needs to read the question and the answers.
     *
     * @param game $game
     * @param question $question
     *
     * @return int Additional read time in seconds. Minimum is 5 seconds. No upper bound.
     */
    private static function calculate_reading_time($game, $question) {
        $word_count = self::count_words_in_question($question);
        $words_per_minute = $game->get_expected_words_per_minute();
        return \max(5, (int)\round((60 / $words_per_minute) * $word_count, 0));
    }

    /**
     * Counts the total number of words in the question text and answer texts.
     *
     * @param question $question
     *
     * @return int
     */
    private static function count_words_in_question($question) {
        $mdl_question = $question->get_mdl_question_ref();
        // count words
        $word_count = self::count_words_in_string($mdl_question->questiontext);
        foreach ($mdl_question->answers as $mdl_answer) {
            $word_count += self::count_words_in_string($mdl_answer->answer);
        }
        return $word_count;
    }

    /**
     * Counts the total number of words in one string. Allows german umlauts in words as well.
     *
     * @param string $str
     *
     * @return int
     */
    private static function count_words_in_string($str) {
        return \str_word_count(\strip_tags($str), 0, "ÄÖÜäöüß");
    }
}
