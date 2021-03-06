<?php

namespace Moznion;

use \Moznion\BenchMarker\Time as Time;
use \Moznion\BenchMarker\Style as Style;

class BenchMarker
{
    // public $do_cache = false;
    // private $cache = array();

    private $min_count = 4;
    private $min_cpu = 0.4;
    private $default_for = 3;

    private $style;
    private $style_name;
    private $format;

    public function __construct($style_name = null, $format = null)
    {
        $this->format = $format;
        if (is_null($this->format)) {
            $this->format = '5.2f';
        }

        if (is_null($style_name)) {
            $style_name = 'auto';
        }
        $this->style = Style::createStyle($style_name, $this->format);
        $this->style_name = ucfirst($style_name);
    }

    /**
     * @param $count
     * @param callable $code
     * @return Time
     */
    public function timeIt($count, callable $code)
    {
        $nop = function () {
            // NOP
        };

        $time_nop = $this->runLoop($count, $nop); // TODO cache it
        $time_code = $this->runLoop($count, $code);

        $result = $this->timeDiff($time_nop, $time_code);
        $result->count = $count;
        return $result;
    }

    public function countIt($time, callable $code)
    {
        $min_for = 0.1;
        $time = $this->nToFor($time);

        if ($time < $min_for) {
            die("countit({$time}, ...): timelimit cannot be less than {$min_for}.\n");
        }

        $tc = null;
        $cnt = 0;
        for ($n = 1; ; $n *= 2) {
            $t0 = Time::getNow();
            $td = $this->timeIt($n, $code);
            $t1 = Time::getNow();

            $tc = $td->user_time + $td->sys_time;

            if ($tc <= 0 and $n > 1024) {
                $diff = $this->timeDiff($t0, $t1);
                if ($diff->user_time + $diff->sys_time > 8 || ++$cnt > 16) {
                    die("Timing is consistently zero in estimation loop, cannot benchmark. N={$n}\n");
                }
            } else {
                $cnt = 0;
            }

            if ($tc > 0.1) {
                break;
            }
        }

        $nmin = $n;
        $time_practice = 0.1 * $time;
        while ($tc < $time_practice) {
            $n = (int)($time_practice * 1.05 * $n / $tc);
            $td = $this->timeIt($n, $code);
            $new_tc = $td->user_time + $td->sys_time;

            $tc = $new_tc > 1.2 * $tc ? $new_tc : 1.2 * $tc;
        }

        $n_total = 0;
        $real_time_total = 0.0;
        $sys_time_total = 0.0;
        $user_time_total = 0.0;
        $child_sys_time_total = 0.0;
        $child_user_time_total = 0.0;

        $n = (int)($n * (1.05 * $time / $tc));
        $cnt = 0;
        while (1) {
            $td = $this->timeIt($n, $code);

            $n_total += $n;
            $real_time_total += $td->real_time;
            $sys_time_total += $td->sys_time;
            $user_time_total += $td->user_time;
            $child_sys_time_total += $td->child_sys_time;
            $child_user_time_total += $td->child_user_time;

            $time_total = $user_time_total + $sys_time_total;
            if ($time_total >= $time) {
                break;
            }

            if ($time_total <= 0) {
                if (++$cnt > 16) {
                    die("Timing is consistently zero, cannot benchmark. N=$n");
                }
            } else {
                $cnt = 0;
            }

            $time_total = $time_total < 0.01 ? 0.01 : $time_total;

            $rate = $time / $time_total - 1;
            $n = (int)($rate * $n_total);
            $n = $n < $nmin ? $nmin : $n;
        }

        $result = new Time(
            $real_time_total,
            $sys_time_total,
            $user_time_total,
            $child_sys_time_total,
            $child_user_time_total
        );

        $result->count = $n_total;

        return $result;
    }

    public function timeThis($count, callable $code, $title = null)
    {
        $forn = null;
        if ($count > 0) {
            if ((int)$count < $count) {
                die("non-integer loop count $count, stopped");
            }
            $result_time = $this->timeIt($count, $code);

            if (is_null($title)) {
                $title = "timethis $count";
            }
        } else {
            $fort = $this->nToFor($count);
            $result_time = $this->countIt($count, $code);
            $forn = $result_time->count;

            if (is_null($title)) {
                $title = "timethis $fort";
            }
        }

        $style = $this->style;
        $style->say(sprintf("%10s: ", $title));

        print $this->timeStr($result_time, $forn) . "\n";

        if (
            $forn < $this->min_count ||
            ($result_time->real_time < 1 && $forn < 1000) ||
            $result_time->getAllCPUTime() < $this->min_cpu
        ) {
            print "            (warning: too few iterations for a reliable count)\n";
        }

        return $result_time;
    }

    /**
     * @param $count
     * @param array $codes
     * @return Time[]
     */
    public function timeThese($count, array $codes)
    {
        $style = $this->style;

        $style->say("Benchmark: ");

        if ($count > 0) {
            if ((int)$count < $count) {
                die("non-integer loopcount $count, stopped");
            }
            $style->say("timing $count iterations of");
        } else {
            $style->say("running");
            $for = $this->nToFor($count);
            if ($count > 1) {
                $style->say(", each");
            }
            $style->say(" for at least $for CPU seconds");
        }

        $results = [];
        $names = array_keys($codes);
        sort($names);

        $style->say(" " . implode(', ', $names));
        $style->say("...\n");

        foreach ($names as $name) {
            $code = $codes[$name];
            if (!is_callable($code)) {
                die("Value of codes must be callable");
            }
            $results[$name] = $this->timeThis($count, $code, $name, $style);
        }
        return $results;
    }

    public function cmpThese($count, array $codes)
    {
        $results = $this->timeThese($count, $codes);

        $rates = [];
        $titles = array_keys($results);
        foreach ($titles as $title) {
            $elapsed = null;
            $result = $results[$title];
            switch ($this->style_name) {
                case 'nop':
                    $elapsed = $result->getChildCPUTime();
                    break;
                case 'noc':
                    $elapsed = $result->getParentCPUTime();
                    break;
                default:
                    $elapsed = $result->getAllCPUTime();
            }

            $rates[$title] = $result->count / ($elapsed + 0.000000000000001);
        }

        $display_as_rate = false;
        if ($rates) {
            $_rates = $rates;
            sort($_rates);
            $display_as_rate = $_rates[(count($_rates) - 1) >> 1] > 1;
        }

        $rows = [];
        $col_widths = [];

        $top_row = ['', $display_as_rate ? 'Rate' : 's/iter'];
        foreach ($titles as $title) {
            array_push($top_row, $title);
        }

        array_push($rows, $top_row);

        foreach ($top_row as $column) {
            array_push($col_widths, strlen($column));
        }

        foreach ($titles as $row_title) {
            $row = [];

            array_push($row, $row_title);

            if (strlen($row_title) > $col_widths[0]) {
                $col_widths[0] = strlen($row_title);
            }

            $result = $results[$row_title];

            $row_rate = $rates[$row_title];
            $rate = $display_as_rate ? $row_rate : 1 / $row_rate;

            if ($rate >= 100) {
                $format = "%0.0f";
            } elseif ($rate >= 10) {
                $format = "%0.1f";
            } elseif ($rate >= 1) {
                $format = "%0.2f";
            } elseif ($rate >= 0.1) {
                $format = "%0.3f";
            } else {
                $format = "%0.2e";
            }

            if ($display_as_rate) {
                $format .= "/s";
            }

            $formatted_rate = sprintf($format, $rate);
            array_push($row, $formatted_rate);

            if (strlen($formatted_rate) > $col_widths[1]) {
                $col_widths[1] = strlen($formatted_rate);
            }

            $skip_rest = false;
            $col_num = 2;
            foreach ($titles as $col_title) {
                $out = '';
                if ($col_title === $row_title) {
                    $out = "--";
                } else {
                    $col_rate = $rates[$col_title];
                    $out = sprintf("%.0f%%", 100 * $row_rate / $col_rate - 100);
                }

                array_push($row, $out);

                if (strlen($out) > $col_widths[$col_num]) {
                    $col_widths[$col_num] = strlen($out);
                }

                if (strlen($row[0]) > $col_widths[$col_num]) {
                    $col_widths[$col_num] = strlen($row[0]);
                }

                $col_num++;
            }

            array_push($rows, $row);
        }

        if ($this->style_name === "None") {
            return $rows;
        }

        for ($i = 0; $i < count($rows); $i++) {
            $row = $rows[$i];
            $str = '';
            for ($j = 0; $j < count($row); $j++) {
                $width = $col_widths[$j];
                $str .= sprintf("%{$width}s  ", $row[$j]);
            }
            echo "{$str}\n";
        }

        return $rows;
    }

    /**
     * @param Time $time_result
     * @param null $count
     * @return string
     */
    public function timeStr(Time $time_result, $count = null)
    {
        $style = $this->style;
        $style_name = $this->style_name;
        if ($style_name === 'None') {
            return '';
        }

        $parent_cpu_time = $time_result->getParentCPUTime();
        $child_cpu_time = $time_result->getChildCPUTime();
        $all_cpu_time = $time_result->getAllCPUTime();

        $real_time = $time_result->real_time;
        $user_time = $time_result->user_time;
        $sys_time = $time_result->sys_time;
        $child_user_time = $time_result->child_user_time;
        $child_sys_time = $time_result->child_sys_time;

        $f = $this->format;

        $time_string = "";
        $elapsed = $all_cpu_time;

        $time_string = $style->spewTimeString(
            $real_time,
            $user_time,
            $sys_time,
            $child_user_time,
            $child_sys_time,
            $all_cpu_time,
            $parent_cpu_time,
            $child_cpu_time
        );

        if ($style_name === "noc") {
            $elapsed = $parent_cpu_time;
        } elseif ($style_name === "nop") {
            $elapsed = $child_cpu_time;
        }

        if ($count && $elapsed) {
            $time_string .= sprintf(" @ %$f/s (n=%d)", $count / ($elapsed), $count);
        }
        return $time_string;
    }

    public function timeDiff(Time $t1, Time $t2)
    {
        return $t2->getDiff($t1);
    }

    /**
     * @param $count
     * @param callable $code
     * @return Time
     */
    private function runLoop($count, callable $code)
    {
        if ($count < 0) {
            die("negative loop count: $count");
        }

        $sub = function () use ($count, $code) {
            for ($i = 0; $i < $count; $i++) {
                $code();
            }
        };

        $t0 = null;
        $time_base = Time::getNow()->user_time;
        while (1) {
            $t0 = Time::getNow();
            if ($t0->user_time !== $time_base) {
                break;
            }
        }

        $sub();

        $t1 = Time::getNow();

        return $this->timeDiff($t0, $t1);
    }

    private function nToFor($n)
    {
        if ($n === 0) {
            return $this->default_for;
        }

        if ($n < 0) {
            return -$n;
        }

        return null;
    }
}

