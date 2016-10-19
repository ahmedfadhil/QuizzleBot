<?php
/**
 * CodeMOOC QuizzleBot
 * ===================
 * UWiClab, University of Urbino
 * ===================
 * Database support library. Don't change a thing here.
 */

require_once('lib_core_database.php');
require_once('lib_utility.php');

//RIDDLES

/**
 * Returns a specific riddle as an array.
 *
 * @param $riddle_id
 * @return mixed
 */
function get_riddle($riddle_id) {
    return db_row_query("SELECT * FROM `riddle` WHERE `id` = {$riddle_id}");
}

/**
 * Returns a specific riddle as an array.
 *
 * @param $riddle_code String the riddle unique string.
 */
function get_riddle_by_code($riddle_code) {

    $riddle_salt = substr($riddle_code,0,2);
    $riddle_id = substr($riddle_code,3);

    return db_row_query("SELECT * FROM `riddle` WHERE `id` = {$riddle_id} AND `salt` = '{$riddle_salt}'");
}

/**
 * Open a new riddle returning the new riddle Id.
 * @return Int New riddle ID.
 */
function open_riddle() {
    $salt = generate_random_salt();

    $riddle_id = db_perform_action("INSERT INTO `riddle` VALUES (DEFAULT, DEFAULT, NULL, NULL, '{$salt}', NULL) ");

    return $riddle_id;
}

/**
 * Returns the url of the QRCode associated to the specific riddle.
 *
 * @param $riddle_id Int The riddle id.
 * @return string The Url of the QRCode of the riddle.
 */
function get_riddle_qrcode_url($riddle_id) {

    $riddle = get_riddle($riddle_id);

    return generate_qr_code_url($riddle[RIDDLE[RIDDLE_SALT]].$riddle_id);

}

/**
 * Closes a riddle.
 *
 * @param $riddle_id Int Riddle ID.
 * @param $text String Answer string.
 * @return int|bool
 */
function close_riddle($riddle_id, $text) {
    if(is_riddle_closed($riddle_id)){
        throw new ErrorException('Riddle already closed');
    }

    $clean_text = db_escape(extract_response($text));

    return db_perform_action("UPDATE `riddle` SET `riddle`.`answer` = '{$clean_text}', `riddle`.`end_time` = CURRENT_TIMESTAMP WHERE `riddle`.`id` = {$riddle_id}");
}

/**
 * Returns the ordered riddle stats (telegram_id, name).
 *
 * @param $riddle_id Int Riddle ID.
 * @return array Answer stats of the closed riddle.
 */
function get_riddle_topten($riddle_id) {

    if(!is_riddle_closed($riddle_id)){
        throw new ErrorException('Riddle still closed');
    }

    return db_table_query("SELECT `answer`.`telegram_id` as telegram_id, IF(`identity`.`group_name` IS NULL, `identity`.`full_name`, `identity`.`group_name` )  FROM `answer` LEFT JOIN `riddle` ON `answer`.`riddle_id` = `riddle`.`id` LEFT JOIN `identity` ON `answer`.`telegram_id` = `identity`.`telegram_id` WHERE `riddle`.`id` = {$riddle_id} AND `riddle`.`answer` = `answer`.`text` AND `riddle`.`answer` IS NOT NULL ORDER BY `answer`.`last_update` ASC LIMIT 10");
}

/**
 * Returns the id of the last open riddles (if there is one).
 *
 * @return Int The id of the last open riddles (if any).
 */
function get_last_open_riddle_id() {
    return db_scalar_query("SELECT id FROM `riddle` WHERE end_time IS NULL ORDER BY `start_time` DESC LIMIT 1");
}

/**
 * Checks whether a riddle is open or not.
 *
 * @param $riddle_id Int The riddle id.
 * @return bool True if the riddle is closed.
 */
function is_riddle_closed($riddle_id) {
    return db_scalar_query("SELECT IF(`answer` IS NULL, '0', '1') FROM `riddle` WHERE id = {$riddle_id}") === 1;
}

/**
 * Sets the channel message id associated to the riddle.
 *
 * @param $riddle_id
 * @param $channel_message_id
 * @return bool|int
 */
function set_riddle_channel_message_id($riddle_id, $channel_message_id){
    return db_perform_action("UPDATE `riddle` SET `channel_message_id` = {$channel_message_id} WHERE `riddle`.`id` = {$riddle_id}");
}

/**
 * Returns the channel message id associated to the riddle.
 *
 * @param $riddle_id
 * @return mixed
 */
function get_riddle_channel_message_id($riddle_id){
    return db_scalar_query("SELECT `channel_message_id` FROM `riddle` WHERE `riddle`.`id` = {$riddle_id}");
}

//ANSWERS

/**
 * Returns a specific answer identified by $telegram_id, $riddle_id, as an array.
 *
 * @param $user_id Int the telegram id of the user.
 * @param $riddle_id Int the riddle id.
 * @return array
 */
function get_answer($telegram_id, $riddle_id) {
    return  db_row_query("SELECT * FROM `answer` WHERE `telegram_id` = {$telegram_id} AND `riddle_id` = {$riddle_id} ORDER BY answer.last_update DESC" );
}

/**
 * Checks whether the answer (identified by $telegram_id, $riddle_id) is correct.
 *
 * @param $user_id
 * @param $riddle_id
 * @return bool
 * @throws ErrorException if the riddle is still open.
 */
function is_answer_correct($telegram_id, $riddle_id) {
    $answer_row = get_answer($telegram_id, $riddle_id);

    if(is_riddle_closed($answer_row[ANSWER[ANSWER_RIDDLE_ID]]) === false){
        throw new ErrorException('Riddle still open');
    }

    $riddle_row = get_riddle($answer_row[ANSWER[ANSWER_RIDDLE_ID]]);

    return strcasecmp (trim($riddle_row[RIDDLE[RIDDLE_ANSWER]]), trim($answer_row[ANSWER[ANSWER_TEXT]])) == 0;
}

/**
 * Removes all answers to a riddle.
 *
 * @param $telegram_id
 * @param $riddle_id
 * @return bool|int
 */
function delete_already_answered($telegram_id, $riddle_id) {
    return db_perform_action("DELETE FROM `answer` WHERE riddle_id = {$riddle_id} AND telegram_id = {$telegram_id}");
}

/**
 * Inserts answer to a riddle.
 * @param $telegram_id Telegram ID of the user.
 * @param $text Answer to register.
 * @param $riddle_id ID of the riddle. Null defaults to last open riddle,. if any.
 * @return bool True on success. False otherwise.
 * @throws ErrorException if there are no open riddles.
 */
function insert_answer($telegram_id, $text, $riddle_id = null) {
    if($riddle_id === null) {
        $riddle_id = get_last_open_riddle_id();
    }

    if($riddle_id) {
        $clean_text = db_escape(extract_response($text));
        return db_perform_action("REPLACE INTO `answer` VALUES ({$telegram_id}, {$riddle_id}, '{$clean_text}', DEFAULT)") === 1;
    }

    throw new ErrorException('No open riddles');
}

//IDENTITIES

/**
 * Gets and refreshes the user's identity.
 *
 * @return array Group name, count of participants, user status and current riddle ID.
 */
function get_identity($telegram_user_id, $first_name, $full_name) {
    $clean_first_name = db_escape($first_name);
    $clean_full_name  = db_escape($full_name);

    $identity = db_row_query("SELECT `group_name`, `participants_count`, `status`, `riddle_id` FROM `identity` WHERE `telegram_id` = {$telegram_user_id}");
    if($identity === null) {
        // New user
        db_perform_action("INSERT INTO `identity` VALUES({$telegram_user_id}, '{$clean_first_name}', '{$clean_full_name}', DEFAULT, DEFAULT, DEFAULT, DEFAULT)");

        return array(null, 1, IDENTITY_STATUS_DEFAULT, null);
    }
    else {
        // Returning user
        db_perform_action("UPDATE `identity` SET `first_name` = '{$clean_first_name}', `full_name` = '{$clean_full_name}' WHERE `telegram_id` = {$telegram_user_id}");

        return $identity;
    }
}

/**
 * Changes the identity group name.
 *
 * @param $telegram_id
 * @param null $group_name
 * @return bool|int
 */
function change_identity_group_name($telegram_id, $group_name = NULL) {
    $group_name_db = is_null($group_name) ? 'NULL' : "'" . db_escape($group_name) . "'";

    return db_perform_action("UPDATE `identity` SET `group_name` = {$group_name_db} WHERE `identity`.`telegram_id` = {$telegram_id}");
}

/**
 * Utility function to change identity status.
 * You SHOULD use one of the set_identity_*_status function instead.
 *
 * @param $telegram_id
 * @param $status
 * @param null $riddle_id
 * @return bool|int
 */
function change_identity_status($telegram_id, $status = IDENTITY_STATUS_TYPE[IDENTITY_STATUS_DEFAULT], $riddle_id = NULL) {
    $riddle_id_db = is_null($riddle_id)? 'NULL': "'$riddle_id'";

    return db_perform_action("UPDATE `identity` SET `status` = {$status}, `riddle_id`  = {$riddle_id_db} WHERE `identity`.`telegram_id` = {$telegram_id}");
}


/**
 * Sets the participants count
 *
 * @param $telegram_id
 * @param int $count DEFAULT is 1
 * @return bool|int
 */
function set_identity_participants_count($telegram_id, $count = 1){
    return db_perform_action("UPDATE `identity` SET `participants_count` = {$count} WHERE `identity`.`telegram_id` = {$telegram_id}");
}


/**
 * Sets the identity status to DEFAULT (0)
 *
 * @param $telegram_id
 * @return bool|int
 */
function set_identity_default_status($telegram_id) {
    return change_identity_status($telegram_id, IDENTITY_STATUS_TYPE[IDENTITY_STATUS_DEFAULT]);
}

/**
 * Sets the identity status to ANSWERING (1)
 *
 * @param $telegram_id
 * @return bool|int
 */
function set_identity_answering_status($telegram_id, $riddle_id) {
    return change_identity_status($telegram_id, IDENTITY_STATUS_TYPE[IDENTITY_STATUS_ANSWERING], $riddle_id);
}

/**
 * Sets the identity status to REGISTERING (2)
 *
 * @param $telegram_id
 * @return bool|int
 */
function set_identity_registering_status($telegram_id) {
    return change_identity_status($telegram_id, IDENTITY_STATUS_TYPE[IDENTITY_STATUS_REGISTERING]);
}



//STATS

/**
 * Returns an array containing respectively
 * number_of_answer, number of participants
 * that answered to the riddle.
 *
 * @param $riddle_id Int The riddle id.
 * @return array
 */
function get_riddle_current_stats($riddle_id){
    return db_row_query("select count(*) as answers, SUM(identity.participants_count) as participants from identity LEFT JOIN answer ON answer.telegram_id = identity.telegram_id where answer.riddle_id = {$riddle_id}");
}


//DB

/**
 * Completely wipes out the DB data.
 */
function reset_db() {
    db_perform_action("START TRANSACTION");
    db_perform_action("TRUNCATE `answer`");
    db_perform_action("DELETE FROM `identity`");
    db_perform_action("ALTER TABLE `identity` AUTO_INCREMENT = 1");
    db_perform_action("DELETE FROM `riddle`");
    db_perform_action("ALTER TABLE `riddle` AUTO_INCREMENT = 1");
    db_perform_action("COMMIT");
}

