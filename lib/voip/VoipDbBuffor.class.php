<?php

/*!
 * \class VoipDbBuffor
 * \brief Class responsibile for optimize SQL queries to database.
 */
class VoipDbBuffor {

    /*!
     * \brief Container for cdr records.
     */
    private $cdr_container = array();

    /*!
     * \brief Used customer tariff rule states.
     * Units are summed and for excluding multiple UPDATE
     * queries for that same voip account id.
     */
    private $used_rules = array();

    /*!
     * \brief Method of providing information for the class.
     */
    private $provider = NULL;

    /*!
     * \brief Container for estimate class.
     */
    private $estimate = NULL;

    /*!
     * \brief Pattern for changing cdr text to array.
     */
    private $pattern = '';

    public function __construct(VoipDataProvider $p) {
        $this->provider = $p;
        $this->estimate = new Estimate($p);
        
        $this->pattern = '/^"(?<caller>(?:\+?[0-9]*|unavailable.*|anonymous.*))",' .
                         '"(.*)",' .
                         '"(?<callee>[0-9]*)",' .
                         '"(?<call_type>(?:incoming.*|outgoing.*))",' .
                         '"(.*)",' .
                         '"(.*)",' .
                         '"(.*)",' .
                         '"(.*)",' .
                         '"(.*)",' .
                         '"(?P<call_start>(?<call_start_year>[0-9]{4})-(?<call_start_month>[0-9]{2})-(?<call_start_day>[0-9]{2}) (?<call_start_hour>[0-9]{2}):(?<call_start_min>[0-9]{2}):(?<call_start_sec>[0-9]{2}))",' .
                         '(?:"(?<call_answer>(?<call_answer_year>[0-9]{4})-(?<call_answer_month>[0-9]{2})-(?<call_answer_day>[0-9]{2}) (?<call_answer_hour>[0-9]{2}):(?<call_answer_min>[0-9]{2}):(?<call_answer_sec>[0-9]{2}))")?,' .
                         '"(?<call_end>(?<call_end_year>[0-9]{4})-(?<call_end_month>[0-9]{2})-(?<call_end_day>[0-9]{2}) (?<call_end_hour>[0-9]{2}):(?<call_end_min>[0-9]{2}):(?<call_end_sec>[0-9]{2}))",(?<time_start_to_end>[0-9]*),(?<time_answer_to_end>[0-9]*),' .
                         '"(?<call_status>.*)",' .
                         '"(.*)",' .
                         '"(?<uniqueid>.*)".*/';
    }

    /*!
     * \brief Add CDR record to list.
     *
     * \param  array/string $c cdr data
     * \return boolean         when all good
     * \return string       $e error message 
     */
    public function appendCdr($c) {
        if (is_array($c))
            $cdr = $c;
        else if (is_string($c))
            $cdr = $this->parseRecord($c);

        $cdr['call_type']   = $this->parseCallType($cdr['call_type']);
        $cdr['call_status'] = $this->parseCallStatus($cdr['call_status']);

        switch ($cdr['call_type']) {
            case CALL_INCOMING: //no payments for incoming call
                $cdr['price'] = 0;
            break;

               case CALL_OUTGOING:
                   if (isset($cdr['time_answer_to_end']) && $cdr['time_answer_to_end'] > 0) {
                    $info  = $this->estimate->getCallCost($cdr['caller'], $cdr['callee'], $cdr['time_answer_to_end']);

                    if ($info['used_rules'])
                        foreach ($info['used_rules'] as $r) {
                            if (isset($this->used_rules[$cdr['caller']][$r['ruleid']]))
                                $this->used_rules[$cdr['caller']][$r['rule_id']]['used_units'] += $r['used_units'];
                            else {
                                $this->used_rules[$cdr['caller']][$r['rule_id']] = $r;
                            }
                        }

                    $cdr['price'] = $info['price'];
                } else {
                    $cdr['price'] = 0;
                }
            break;
        }

        if (($e = $this->validRecord($cdr)) === TRUE) {
            $this->cdr_container[] = $cdr;
            return TRUE;
        }

          return $e;
    }

    /*!
     * \brief Add used tariff rule units to list.
     *
     * \param int $v_id voip account id
     * \param int $r_id tariff rule id
     * \param int $u    used units
     */
    public function appendRuleState($v_id, $r_id, $u) {
        if (isset($this->rule_states[$v_id][$r_id])) {
            $this->rule_states[$v_id][$r_id] += $u;
        } else {
            $this->rule_states[$v_id][$r_id] = $u;
        }
    }

    /*!
     * \brief Method insert appended informations to data base.
     */
    public function insert() {
        $P         = $this->provider;
        $insert    = array();
        $cust_load = array();

        foreach ($this->cdr_container as $c) {
            $caller    = $P->getCustomerByPhone($c['caller']);
            $callee    = $P->getCustomerByPhone($c['callee']);
            $caller_gr = $P->getPrefixGroupName($caller['phone'], $caller['tariffid']);
            $callee_gr = $P->getPrefixGroupName($callee['phone'], $caller['tariffid']);

            $insert[] = "('" . $c['caller']             . "'," .
                        "'"  . $c['callee']             . "'," .
                               $c['call_start']         . ',' .
                               $c['time_start_to_end']  . ',' .
                               $c['time_answer_to_end'] . ',' .
                               $c['price']              . ',' .
                               $c['call_status']        . ',' .
                               $c['call_type']          . ',' .
                               ($caller['voipaccountid'] ? $caller['voipaccountid'] : 'NULL') . ',' .
                               ($callee['voipaccountid'] ? $callee['voipaccountid'] : 'NULL') . ',' .
                               ((int) $caller['flags']) . ',' .
                               ((int) $callee['flags']) . ',' .
                               ($caller_gr               ? "'$caller_gr'"           : 'NULL') . ',' .
                               ($callee_gr               ? "'$callee_gr'"           : 'NULL') . ',' .
                               $c['uniqueid']           . ')';

            if ($c['price'] > 0) {
                if (isset($cust_load[$c['caller']]))
                    $cust_load[$c['caller']] += $c['price'];
                else
                    $cust_load[$c['caller']] = $c['price'];
            }
        }

        $DB = LMSDB::getInstance();
        $DB->BeginTrans();

        //insert cdr records
        $DB->Execute('INSERT INTO voip_cdr
                         (caller, callee, call_start_time, time_start_to_end, time_answer_to_end,
                          price, status, type, callervoipaccountid, calleevoipaccountid, caller_flags,
                          callee_flags, caller_prefix_group, callee_prefix_group, uniqueid)
                      VALUES ' . implode(',', $insert));

        //update customer account balance
        foreach ($cust_load as $k=>$v) {
            $DB->Execute('UPDATE voipaccounts SET balance=balance-? WHERE phone ?LIKE? ?;', array($v, $k));
        }

        //update customer tariff rules
        $new_rules = array();
        foreach ($this->used_rules as $rule_list) {

            foreach ($rule_list as $r) {
                $exists = $DB->GetOne('SELECT 1
                                       FROM 
                                          voip_rule_states
                                        WHERE
                                             voip_account_id = ? AND
                                             rule_id         = ?;',
                                        array($r['voip_acc_id'], $r['rule_id']));

                if ($exists) {
                    $DB->Execute('UPDATE voip_rule_states
                                  SET units_left = units_left - ?
                                  WHERE
                                      voip_account_id = ? AND
                                      rule_id         = ?;',
                                  array($r['used_units'], $r['voip_acc_id'], $r['rule_id']));
                } else {
                    $new_rules[] = '(' . $r['voip_acc_id'] . ','
                                       . $r['rule_id']     . ','
                                       . $r['rule_units']-$r['used_units'] . ')';
                }
            }
        }

        if ($new_rules) {
            $DB->Execute('INSERT INTO voip_rule_states
                              (voip_account_id, rule_id, units_left)
                          VALUES ' . implode(',', $new_rules));
        }

        $DB->CommitTrans();
        $this->cdr_container = array();
        $this->used_rules    = array();
    }

    /*!
     * \brief Change call type (string) to defined number (int).
     *
     * \param string $type call type
     * \return int       number assigned to call type
     * \return exception when can't match string to call type
     */
    public function parseCallType($type) {
        if (preg_match("/incoming/i", $type))
            return CALL_INCOMING;

        if (preg_match("/outgoing/i", $type))
            return CALL_OUTGOING;

        return 'incorect';
    }

    /*!
     * \brief Change call status (string) to defined number (int).
     *
     * \param string $type call type
     * \return int number assigned to call status
     * \return php_function die when can't match string to call type
     */
    public function parseCallStatus($type) {
        if (preg_match("/busy/i", $type))
            return CALL_BUSY;

        if (preg_match("/answered/i", $type))
            return CALL_ANSWERED;

        if (preg_match("/(noanswer|no answer)/i", $type))
            return CALL_NO_ANSWER;

        if (preg_match("/fail/i", $type))
            return CALL_SERVER_FAILED;

        return 'incorect';
    }

    /*!
     * \brief Change text to asociative array.
     *
     * \param  string $r string to parse
     * \return array     associative array with paremeters
     */
    private function parseRecord($r) {
        preg_match($this->pattern, $r, $matches);

        $matches['call_start'] = mktime($matches['call_start_hour'],
                                        $matches['call_start_min'],
                                        $matches['call_start_sec'],
                                        $matches['call_start_month'],
                                        $matches['call_start_day'],
                                        $matches['call_start_year']);

        foreach ($matches as $k=>$v) {
            if (is_numeric($k))
                unset($matches[$k]);
            else if (!$matches[$k])
                $matches[$k] = 0;
        }

        return $matches;
    }

    /*!
     * \brief Valid array with cdr data.
     *
     * \param  array   cdr record
     * \return boolean when all good
     * \return string  first founded error description
     */
    private function validRecord($r) {
        $error = '';

        if (!isset($r['caller']))
            $error = "Caller phone number isn't set.";
        if (!preg_match("/([0-9]+|anonymous|unavailable)/", $r['caller']))
            $error = "Caller phone number has incorrect format.";

        if (!isset($r['callee']))
            $error = "Callee phone number isn't set.";
        if (!is_numeric($r['callee']))
            $error = "Callee phone number has incorrect format.";

        if (!isset($r['call_type']))
            $error = "Call type isn't set.";
        else if (!is_int($r['call_type']))
            $error = "Call type has incorrect format.";

        if (!isset($r['call_start']))
            $error = "Call start isn't set.";
        else if (!is_numeric($r['call_start']))
            $error = "Call start time has incorrect format.";

        if (!isset($r['time_start_to_end']))
            $error = "Time start to end isn't set.";
        else if (!is_numeric($r['time_start_to_end']))
            $error = "Time start to end has incorrect format.";

        if (!isset($r['time_answer_to_end']))
            $error = "Time answer to end isn't set.";
        else if (!is_numeric($r['time_answer_to_end']))
            $error = "Time answer to end has incorract format.";

        if (!isset($r['uniqueid']))
            $error = "Call unique id isn't set.";
        else if (!preg_match("/[0-9]*\.[0-9]*/i", $r['uniqueid']))
            $error = "Call unique id has incorrect format.";

        if (!isset($r['price']))
            $error = "Price ist't set.";
        else if (!is_numeric($r['price']))
            $error = "Price has incorrect format.";

        if ($error)
            return $error;

        return TRUE;
    }
}

?>
