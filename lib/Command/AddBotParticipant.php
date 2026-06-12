<?php

namespace OCA\NCdiscordhook\Command;

use OCA\Talk\Manager as TalkManager;
use OCA\Talk\Participant;
use OCA\Talk\Model\Attendee;
use OCA\Talk\Model\AttendeeMapper;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\DB\Exception;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\IUserManager;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class AddBotParticipant extends Command {
    public static defaultName = 'ncdiscordhook:add-bot-participant';
    public static defaultDescription = 'Add talk-bot as a participant in all configured rooms (fixes Chat API 404)';

    private IConfig $config;
    private IDBConnection $db;
    private TalkManager $talkManager;
    private IUserManager $userManager;
    private AttendeeMapper $attendeeMapper;

    private const APP_ID = 'ncdiscordhook';

    public function __construct(
        IConfig $config,
        IDBConnection $db,
        TalkManager $talkManager,
        IUserManager $userManager,
        AttendeeMapper $attendeeMapper,
    ) {
        parent::__construct();
        $this->config = $config;
        $this->db = $db;
        $this->talkManager = $talkManager;
        $this->userManager = $userManager;
        $this->attendeeMapper = $attendeeMapper;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int {
        $output->writeln('Reading configured rooms from app config...');

        // Get configured rooms from app config (stored as JSON)
        $roomsJson = $this->config->getAppValue(self::APP_ID, 'rooms', '[]');
        $rooms = @json_decode($roomsJson, true);
        if (!is_array($rooms)) {
            $output->writeln('<error>No configured rooms found.</error>');
            return 1;
        }

        $roomTokens = array_keys($rooms);
        $output->writeln('Found ' . count($roomTokens) . ' configured room(s).');

        // Get talk-bot user
        $botUser = $this->userManager->get('talk-bot');
        if ($botUser === null) {
            $output->writeln('<error>talk-bot user not found. Creating it...</error>');
            // talk-bot might not exist yet
            $output->writeln('<comment>Run: php occ user:add --password-from-env talk-bot</comment>');
            return 1;
        }

        // Get table prefix
        $sysPrefix = $this->config->getSystemValueString('dbtableprefix', '');
        $talkPrefix = $this->config->getAppValue('spreed', 'databaseprefix', $sysPrefix);
        $attendeeTable = $talkPrefix . 'talk_attendee';

        $added = 0;
        $skipped = 0;

        foreach ($roomTokens as $token) {
            try {
                $room = $this->talkManager->getRoomForToken($token);
                $roomId = $room->getId();

                // Check if talk-bot is already an attendee
                try {
                    $attendee = $this->attendeeMapper->findByActor($roomId, 'users', 'talk-bot');
                    $output->writeln('  <info>Room ' . $token . ': talk-bot already an attendee (id=' . $roomId . ')</info>');
                    $skipped++;
                    continue;
                } catch (DoesNotExistException $e) {
                    // Not found - will create
                } catch (\Exception $e) {
                    $output->writeln('  <warning>Room ' . $token . ': query error - ' . $e->getMessage() . '</warning>');
                    $skipped++;
                    continue;
                }

                // Create attendee record
                $newAttendee = new Attendee();
                $newAttendee->setRoomId($roomId);
                $newAttendee->setActorType('users');
                $newAttendee->setActorId('talk-bot');
                $newAttendee->setDisplayName('talk-bot');
                $newAttendee->setParticipantType(Participant::PERMISSIONS_DEFAULT);
                $newAttendee->setPermissions(Participant::PERMISSIONS_MAX_DEFAULT);
                $newAttendee->setNotificationLevel(3); // Full level
                $newAttendee->setFavorite(false);
                $newAttendee->setArchived(false);

                try {
                    $this->attendeeMapper->insert($newAttendee);
                    $output->writeln('  <info>Room ' . $token . ': added talk-bot as attendee (room_id=' . $roomId . ')</info>');
                    $added++;
                } catch (Exception $e) {
                    $output->writeln('  <warning>Room ' . $token . ': insert failed - ' . $e->getMessage() . '</warning>');
                }
            } catch (\Exception $e) {
                $output->writeln('  <error>Room ' . $token . ': ' . $e->getMessage() . '</error>');
            }
        }

        $output->writeln('');
        $output->writeln('<info>Done: ' . $added . ' added, ' . $skipped . ' skipped.</info>');
        return 0;
    }
}
