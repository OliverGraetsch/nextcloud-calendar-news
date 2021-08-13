<?php
/**
 * @copyright Copyright (c) 2020-2021 Marco Ziech <marco+nc@ziech.net>
 *
 * @license GNU AGPL version 3 or any later version
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 *
 */
namespace OCA\CalendarNews\Service;


use OCP\Calendar\IManager;
use OCP\IConfig;
use OCP\IUserManager;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

class ScheduleService {

    /**
     * @var IConfig
     */
    private $config;
    private $AppName;
    /**
     * @var NewsletterService
     */
    private $newsletterService;
    /**
     * @var LoggerInterface
     */
    private $logger;
    /**
     * @var IManager
     */
    private $calendarManager;
    /**
     * @var IUserManager
     */
    private $userManager;
    /**
     * @var IUserSession
     */
    private $userSession;

    function __construct(
        $AppName,
        IConfig $config,
        IManager $calendarManager,
        NewsletterService $newsletterService,
        IUserManager $userManager,
        IUserSession  $userSession,
        LoggerInterface $logger
    ) {
        $this->config = $config;
        $this->AppName = $AppName;
        $this->newsletterService = $newsletterService;
        $this->logger = $logger;
        $this->calendarManager = $calendarManager;
        $this->userManager = $userManager;
        $this->userSession = $userSession;
    }

    // TODO: make newsletter config user-specific so we don't need this ugly hack *sigh*
    public function findSuitableUser(): void {
        $required = $this->newsletterService->getRequiredCalendarIds();
        $this->logger->info("Looking for user which has access to calendars: " . implode(", ", $required));
        foreach ($this->userManager->search("") as $user) {
            $this->logger->debug("Checking whether {$user->getUID()} is suitable to send newsletter");
            $this->userSession->setUser($user);
            // just find first user with access to all required calendars, it doesn't really matter
            if ($this->isSuitableUser($required)) {
                $this->logger->info("Sending newsletter as user: {$user->getUID()}");
                return;
            }
        }
        throw new \RuntimeException("No suitable user found for sending newsletter");
    }

    private function isSuitableUser($required) {
        return empty(array_diff(
            $required,
            array_map(function ($it) {
                return $it->getKey();
            }, $this->calendarManager->getCalendars())
        ));
    }

    public function load() {
        $str = $this->config->getAppValue($this->AppName, "schedule");
        if ($str != "") {
            return json_decode($str, true);
        }

        return [
            "schedule" => [
                "subject" => "",
                "emails" => [],
                "repeatInterval" => "off",
            ]
        ];
    }

    public function save($schedule) {
        $this->config->setAppValue($this->AppName, "schedule", json_encode($schedule));
    }

    public function sendNow() {
        $this->logger->info("Sending newsletter now");

        $schedule = $this->load();
        $this->newsletterService->send($schedule["schedule"]["emails"], $schedule["schedule"]["subject"]);
    }

    public function getLastExecutionTime() {
        $str = $this->config->getAppValue($this->AppName, "schedule.lastExecutionTime");
        if ($str != "") {
            return \DateTime::createFromFormat(\DateTimeInterface::ISO8601, $str);
        }
        return null;
    }

    public function setLastExecutionTime(\DateTime $t) {
        $this->config->setAppValue($this->AppName, "schedule.lastExecutionTime", $t->format(\DateTimeInterface::ISO8601));
    }

    public function removeLastExecutionTime() {
        $this->config->setAppValue($this->AppName, "schedule.lastExecutionTime", null);
    }

    public function getNextExecutionTime($schedule=null) {
        if ($schedule == null) {
            $schedule = $this->load()["schedule"];
        }
        $t = $this->getLastExecutionTime();
        if ($t == null) {
            $t = new \DateTime();
            $t->modify("yesterday");
        }
        $t->setTimezone(new \DateTimeZone("Europe/Berlin"));
        $rt = \DateTime::createFromFormat("Y-m-d\\TH:i:s.uO", $schedule["repeatTime"]);
        switch ($schedule["repeatInterval"]) {
            case "yearly":
                if ($schedule["skip"]) {
                    $t->modify($schedule["skip"] . " years");
                }
                $t->modify("1 year");
                $t->modify($schedule["repeatWeek"] . " " . $schedule["repeatWeekday"]
                    . " of " . $schedule["repeatMonth"]);
                break;
            case "yearly_dom":
                if ($schedule["skip"]) {
                    $t->modify($schedule["skip"] . " years");
                }
                $t->modify("1 year");
                $t->modify(($schedule["repeatDayOfMonth"] > 0 ? "first" : "last")
                    . " day of " . $schedule["repeatMonth"]);
                if ($schedule["repeatDayOfMonth"] !== 0) {
                    $t->modify(($schedule["repeatDayOfMonth"] - 1) . " days");
                }
                break;
            case "monthly":
                if ($schedule["skip"]) {
                    $t->modify($schedule["skip"] . " months");
                }
                $t->modify($schedule["repeatWeek"] . " " . $schedule["repeatWeekday"]
                    . " of next month");
                break;
            case "monthly_dom":
                if ($schedule["skip"]) {
                    $t->modify($schedule["skip"] . " months");
                }
                $t->modify(($schedule["repeatDayOfMonth"] > 0 ? "first" : "last") . " day of next month");
                if ($schedule["repeatDayOfMonth"] !== 0) {
                    $t->modify(($schedule["repeatDayOfMonth"] - 1) . " days");
                }
                break;
            case "weekly":
                if ($schedule["skip"]) {
                    $t->modify($schedule["skip"] . " weeks");
                }
                $t->modify("next " . $schedule["repeatWeekday"]);
                break;
            case "daily":
                if ($schedule["skip"]) {
                    $t->modify($schedule["skip"] . " days");
                }
                $t->modify("tomorrow");
                break;
            case "off":
            default:
                return null;
        }
        $t->modify($rt->format("H:i"));
        return $t;
    }

}