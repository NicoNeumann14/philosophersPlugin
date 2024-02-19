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

namespace mod_philosophers\external\exporter;

use context;
use core\external\exporter;
use mod_philosophers\model\game;
use mod_philosophers\model\gamesession;
use renderer_base;

defined('MOODLE_INTERNAL') || die();

/**
 * Class gamesession_dto
 *
 * @package    mod_philosophers\external\exporter
 * @copyright  2019 Benedikt Kulmann <b@kulmann.biz>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class gamesession_dto extends exporter {

    /**
     * @var gamesession
     */
    protected $gamesession;
    /**
     * @var game
     */
    protected $game;

    /**
     * gamesession_dto constructor.
     *
     * @param gamesession $gamesession
     * @param game $game
     * @param context $context
     *
     * @throws \coding_exception
     */
    public function __construct(gamesession $gamesession, game $game, context $context) {
        $this->gamesession = $gamesession;
        $this->game = $game;
        parent::__construct([], ['context' => $context]);
    }

    protected static function define_other_properties() {
        return [
            'id' => [
                'type' => PARAM_INT,
                'description' => 'gamesession id',
            ],
            'timecreated' => [
                'type' => PARAM_INT,
                'description' => 'timestamp of the creation of the gamesession',
            ],
            'timemodified' => [
                'type' => PARAM_INT,
                'description' => 'timestamp of the last modification of the gamesession',
            ],
            'game' => [
                'type' => PARAM_INT,
                'description' => 'philosophers instance id',
            ],
            'mdl_user' => [
                'type' => PARAM_INT,
                'description' => 'id of the moodle user who owns this gamesession',
            ],
            'score' => [
                'type' => PARAM_INT,
                'description' => 'the current score of the user in this gamesession',
            ],
            'answers_total' => [
                'type' => PARAM_INT,
                'description' => 'the total number of answers the user has already given in this gamesession',
            ],
            'answers_correct' => [
                'type' => PARAM_INT,
                'description' => 'the number of correct answers the user has already given in this gamesession',
            ],
            'state' => [
                'type' => PARAM_TEXT,
                'description' => 'progress, finished or dumped',
            ],
        ];
    }

    protected static function define_related() {
        return [
            'context' => 'context',
        ];
    }

    protected function get_other_values(renderer_base $output) {
        return $this->gamesession->to_array();
    }
}
