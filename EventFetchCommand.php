<?php
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Artack\DOMQuery\DOMQuery;

class EventFetchCommand extends Command
{
    protected static $defaultName = 'app:event-fetch';
    protected $idCounters = [
        "event" => 5000,
        "post" => 5000
    ];
    protected $author = 1;

    protected $sources = [
        4 => "https://www.kolumbuspm.cz/nabidka-skoleni/",
        5 => "https://www.kolumbuspm.cz/nabidka-skoleni-brno/",
        6 => "https://www.kolumbuspm.cz/nabidka-skoleni-ostrava-2019/"
    ];

    protected $months = [
        1 => ["leden", "ledna"],
        2 => ["únor", "února"],
        3 => ["březen", "března"],
        4 => ["duben", "dubna"],
        5 => ["květen", "května"],
        6 => ["červen", "června"],
        7 => ["červenec", "července"],
        8 => ["srpen", "srpna"],
        9 => ["září"],
        10 => ["říjen", "října"],
        11 => ["listopad", "listopadu"],
        12 => ["prosinec", "prosince"]
    ];

    protected function configure()
    {
        $this
            ->setDescription('Fetches events from Kolumbus PM.')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $res = [];
        $sql = [];

        foreach ($this->sources as $locationId => $url) {
            $html = file_get_contents($url);
            $q = DOMQuery::create($html);

            $events = $q->find('.mt-i.cf');

            foreach ($events as $event) {
                $item = [];
                $item["name"] = $this->getText($event, "h3");
                $item["description"] = $this->getHtml($event, ".text-content.cf");
                $eventDescription = $event->find(".text-content.cf")->getFirst();
                $item["lector"] = $this->getText($eventDescription, ".wsw-12");
                $item["time"] = $this->parseTime($eventDescription->getHtml());
                $item["gmt_time"] = $this->transformToGmt($item["time"]);
                $item["slug"] = $this->slugify($item["name"]);
                $item["location_id"] = $locationId;
                $item["price"] = preg_replace('/[^0-9]/', '', $this->getText($event, ".text.cf.design-01:nth-child(2) h3"));
                if ($item["time"]) {
                    $item["event_id"] = $this->idCounters["event"]++;
                    $item["post_id"] = $this->idCounters["post"]++;
                    $res[] = $item;
                }
            }
        }

        $now = new DateTime();
        $gmtNow = ["now" => $now];
        $gmtNow = $this->transformToGmt($gmtNow);
        $gmtNow = $gmtNow["now"];

        $now = $now->format("Y-m-d H:i:s");
        $gmtNow = $gmtNow->format("Y-m-d H:i:s");

        $lectors = array_map(function($a) {
            return trim(str_replace("&nbsp;", "", $a["lector"]));
        }, $res);
        $lectors = array_unique(array_filter($lectors));
        $sql[] = "UPDATE `wp_options` SET `option_value` = '" . implode("\n", $lectors) . "' WHERE `option_name` = 'dbem_lectors';";

        foreach ($res as $i => $item) {
            $data = [
                "ID" => $item["post_id"],
                "post_author" => $this->author,
                "post_date" => $now,
                "post_date_gmt" => $gmtNow,
                "post_modified" => $now,
                "post_modified_gmt" => $gmtNow,
                "post_content" => $this->prepareString($item["description"]),
                "post_title" => $this->prepareString($item["name"]),
                "post_status" => "publish",
                "post_name" => $this->prepareString($item["slug"]),
                "post_type" => "event",
                "guid" => sprintf("http://localhost/kolumbus/?post_type=event&#038;p=%s", $item["post_id"]),
                "post_excerpt" => "",
                "to_ping" => "",
                "pinged" => "",
                "post_content_filtered" => ""
            ];
            foreach ($data as $key => $val) {
                $data[$key] = "'" . $val . "'";
            }
            $sql[] = "INSERT INTO `wp_posts` (" . implode(",", array_keys($data)) . ") VALUES (" . implode(",", array_values($data)) . ");";
        }

        foreach ($res as $i => $item) {
            $data = [
                "event_id" => $item["event_id"],
                "post_id" => $item["post_id"],
                "event_slug" => $this->prepareString($item["slug"]),
                "event_owner" => $this->author,
                "event_status" => 1,
                "event_name" => $this->prepareString($item["name"]),
                "event_start_date" => $item["time"]["from"]->format("Y-m-d"),
                "event_end_date" => $item["time"]["to"]->format("Y-m-d"),
                "event_start_time" => $item["time"]["from"]->format("H:i:s"),
                "event_end_time" => $item["time"]["to"]->format("H:i:s"),
                "event_start" => $item["gmt_time"]["from"]->format("Y-m-d H:i:s"),
                "event_end" => $item["gmt_time"]["to"]->format("Y-m-d H:i:s"),
                "event_timezone" => "Europe/Prague",
                "post_content" => $this->prepareString($item["description"]),
                "event_rsvp" => 1,
                "event_rsvp_date" => $item["time"]["from"]->format("Y-m-d"),
                "event_rsvp_time" => $item["time"]["from"]->format("H:i:s"),
                "event_lector_name" => $item["lector"],
                "event_date_created" => $now,
                "location_id" => $item["location_id"]
            ];
            foreach ($data as $key => $val) {
                $data[$key] = "'" . $val . "'";
            }
            $sql[] = "INSERT INTO `wp_em_events` (" . implode(",", array_keys($data)) . ") VALUES (" . implode(",", array_values($data)) . ");";
        }

        foreach ($res as $i => $item) {
            $data = [
                "_edit_last" => 0,
                "_event_id" => $item["event_id"],
                "_event_timezone" => "Europe/Prague",
                "_event_start_time" => $item["time"]["from"]->format("H:i:s"),
                "_event_end_time" => $item["time"]["to"]->format("H:i:s"),
                "_event_start" => $item["time"]["from"]->format("Y-m-d H:i:s"),
                "_event_end" => $item["time"]["to"]->format("Y-m-d H:i:s"),
                "_event_start_date" => $item["time"]["from"]->format("Y-m-d"),
                "_event_end_date" => $item["time"]["to"]->format("Y-m-d"),
                "_event_rsvp" => 1,
                "_event_rsvp_date" => $item["time"]["from"]->format("Y-m-d"),
                "_event_rsvp_time" => $item["time"]["from"]->format("H:i:s"),
                "_event_rsvp_spaces" => 50,
                "_event_spaces" => 0,
                "_location_id" => $locationId,
                "_event_start_local" => $item["gmt_time"]["from"]->format("Y-m-d H:i:s"),
                "_event_end_local" => $item["gmt_time"]["to"]->format("Y-m-d H:i:s")
            ];
            foreach ($data as $key => $val) {
                $itemData = [$item["post_id"], $key, $val];
                foreach ($itemData as $key => $val) {
                    $itemData[$key] = "'" . $val . "'";
                }
                $sql[] = "INSERT INTO `wp_postmeta` (`post_id`,`meta_key`,`meta_value`) VALUES (" . implode(',', $itemData) . ");";
            }
        }

        foreach ($res as $i => $item) {
            $data = [
                "event_id" => $item["event_id"],
                "ticket_name" => "registrace",
                "ticket_description" => "",
                "ticket_price" => $item["price"],
                "ticket_spaces" => 50,
                "ticket_members" => 0,
                "ticket_guests" => 0,
                "ticket_required" => 0
            ];
            foreach ($data as $key => $val) {
                $data[$key] = "'" . $val . "'";
            }
            $sql[] = "INSERT INTO `wp_em_tickets` (" . implode(",", array_keys($data)) . ") VALUES (" . implode(",", array_values($data)) . ");";
        }

        file_put_contents("result.sql", implode("\n", $sql));

        $output->writeln("Done.");
    }

    protected function prepareString($txt)
    {
        return str_replace("'", '"', $txt);
    }

    protected function getHtml($q, $selector)
    {
        return $q->find($selector)->getFirst()->getInnerHtml();
    }

    protected function getText($q, $selector)
    {
        return strip_tags($this->getHtml($q, $selector));
    }

    protected function parseTime($text)
    {
        $pattern = '/(\d+)\.(.*)(\d{4})[^\d]*(\d+\:\d+)[^\d]*(\d+\:\d+),/';
        $hit = preg_match($pattern, $text, $matches);
        if ($hit) {
            $res = $this->parseSimpleTime($matches);
            if ($res) {
                list($from, $to) = $res;
                return ["from" => $from, "to" => $to];
            }
        }

        return null;
    }

    protected function parseSimpleTime($matches)
    {
        $day = $matches[1];
        $month = $this->getMonth($matches[2]);
        if (null === $month) {
            return false;
        }
        $year = $matches[3];
        $f = explode(":", $matches[4]);
        $t = explode(":", $matches[5]);

        $from = new DateTime();
        $from->setDate($year, $month, $day);
        $from->setTime($f[0], $f[1]);

        $to = new DateTime();
        $to->setDate($year, $month, $day);
        $to->setTime($t[0], $t[1]);

        return [$from, $to];
    }

    protected function getMonth($name)
    {
        $name = trim($name);
        $month = null;

        foreach ($this->months as $i => $arr) {
            foreach ($arr as $mon) {
                if ($name === $mon) {
                    $month = $i;
                    break 2;
                }
            }
        }

        return $month;
    }

    protected function slugify($text)
    {
        // replace non letter or digits by -
        $text = preg_replace('~[^\pL\d]+~u', '-', $text);

        // transliterate
        $text = iconv('utf-8', 'us-ascii//TRANSLIT', $text);

        // remove unwanted characters
        $text = preg_replace('~[^-\w]+~', '', $text);

        // trim
        $text = trim($text, '-');

        // remove duplicate -
        $text = preg_replace('~-+~', '-', $text);

        // lowercase
        $text = strtolower($text);

        if (empty($text)) {
            return 'n-a';
        }

        return $text;
    }

    protected function transformToGmt($times)
    {
        if (!is_array($times)) {
            return $times;
        }

        $res = [];

        foreach ($times as $i => $val) {
            $gmtDate = gmdate('Y-m-d H:i:s', strtotime($val->format("Y-m-d H:i:s")));
            $gmt = new DateTime($gmtDate);
            $res[$i] = $gmt;
        }

        return $res;
    }
}
