<?php
defined('MOODLE_INTERNAL') || die();

$functions = [
    'mod_insightjournal_save_entry' => [
        'classname' => 'mod_insightjournal\\external\\save_entry',
        'methodname' => 'execute',
        'description' => 'Save or update an insight journal entry',
        'type' => 'write',
        'ajax' => true,
        'capabilities' => 'mod/insightjournal:submit',
    ],
];
