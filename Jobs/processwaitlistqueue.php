<?php

/**
*  Cron Example:
*  This script must be executed every minute for to enable waitlist FIFO auto-booking
*  * * * * * /usr/bin/env php -f ${WWW_DIR}/librebooking/Jobs/processwaitlistqueue.php
*/

define('ROOT_DIR', __DIR__ . '/../');
require_once(ROOT_DIR . 'Domain/Access/namespace.php');
require_once(ROOT_DIR . 'Jobs/JobCop.php');
require_once(ROOT_DIR . 'lib/Application/Authorization/namespace.php');
require_once(ROOT_DIR . 'lib/Application/Authentication/CSRFToken.php');
require_once(ROOT_DIR . 'Pages/Ajax/ReservationSavePage.php');
require_once(ROOT_DIR . 'Presenters/Reservation/ReservationPresenterFactory.php');

use LibreBooking\Application\Authentication\UserSessionBuilder;

/**
 * IReservationSavePage adapter that feeds a stored waitlist request into the
 * standard reservation save pipeline (ReservationPresenterFactory::Create ->
 * BuildReservation -> HandleReservation), mirroring the Web service API.
 *
 * Declared before the executable script code below on purpose: the interface
 * is loaded at runtime, so PHP defers this class declaration to that point
 * and it would not exist yet while the script body runs.
 */
class WaitlistReservationSavePage implements IReservationSavePage
{
    private string|null $referenceNumber = null;
    private bool $requiresApproval = false;
    /**
     * @var array|string[]
     */
    private array $errors = [];

    /**
     * @param ReservationWaitlistRequest $request
     * @param UserSession $session
     */
    public function __construct(private ReservationWaitlistRequest $request, private UserSession $session)
    {
    }

    public function GetUserId()
    {
        return $this->request->UserId();
    }

    public function GetResourceId()
    {
        return $this->request->ResourceId();
    }

    public function GetTitle()
    {
        return '';
    }

    public function GetDescription()
    {
        return '';
    }

    public function GetStartDate()
    {
        return $this->request->StartDate()->ToTimezone($this->Timezone())->Format('Y-m-d');
    }

    public function GetEndDate()
    {
        return $this->request->EndDate()->ToTimezone($this->Timezone())->Format('Y-m-d');
    }

    public function GetStartTime()
    {
        return $this->request->StartDate()->ToTimezone($this->Timezone())->Format('H:i');
    }

    public function GetEndTime()
    {
        return $this->request->EndDate()->ToTimezone($this->Timezone())->Format('H:i');
    }

    public function GetResources()
    {
        return [];
    }

    public function GetParticipants()
    {
        return [];
    }

    public function GetInvitees()
    {
        return [];
    }

    public function SetReferenceNumber($referenceNumber)
    {
        $this->referenceNumber = $referenceNumber;
    }

    public function SetRequiresApproval($requiresApproval)
    {
        $this->requiresApproval = $requiresApproval;
    }

    public function GetAccessories()
    {
        return [];
    }

    public function GetAttributes()
    {
        return [];
    }

    public function GetAttachments()
    {
        return [];
    }

    public function HasStartReminder()
    {
        return false;
    }

    public function GetStartReminderValue()
    {
        return '';
    }

    public function GetStartReminderInterval()
    {
        return '';
    }

    public function HasEndReminder()
    {
        return false;
    }

    public function GetEndReminderValue()
    {
        return '';
    }

    public function GetEndReminderInterval()
    {
        return '';
    }

    public function GetAllowParticipation()
    {
        return false;
    }

    public function GetParticipatingGuests()
    {
        return [];
    }

    public function GetInvitedGuests()
    {
        return [];
    }

    /**
     * The user asked for exactly this slot by joining the waitlist, so treat
     * the request as an acknowledgement of the terms of service.
     */
    public function GetTermsOfServiceAcknowledgement()
    {
        return true;
    }

    public function GetRepeatType()
    {
        return RepeatType::None;
    }

    public function GetRepeatInterval()
    {
        return null;
    }

    public function GetRepeatWeekdays()
    {
        return null;
    }

    public function GetRepeatMonthlyType()
    {
        return null;
    }

    public function GetRepeatTerminationDate()
    {
        return null;
    }

    public function GetRepeatCustomDates()
    {
        return [];
    }

    public function SetSaveSuccessfulMessage($succeeded)
    {
        // no-op
    }

    public function SetErrors($errors)
    {
        $this->errors = $errors;
    }

    public function SetWarnings($warnings)
    {
        // no-op
    }

    public function SetRetryMessages($messages)
    {
        // no-op
    }

    public function SetCanBeRetried($canBeRetried)
    {
        // no-op
    }

    public function SetRetryParameters($retryParameters)
    {
        // no-op
    }

    public function GetRetryParameters()
    {
        return [];
    }

    public function SetCanJoinWaitList($canJoinWaitlist)
    {
        // no-op
    }

    /**
     * @return string|null
     */
    public function ReferenceNumber()
    {
        return $this->referenceNumber;
    }

    public function RequiresApproval()
    {
        return $this->requiresApproval;
    }

    /**
     * @return array|string[]
     */
    public function Errors()
    {
        return $this->errors;
    }

    /**
     * @return string
     */
    private function Timezone()
    {
        return !empty($this->session->Timezone) ? $this->session->Timezone : Configuration::Instance()->GetDefaultTimezone();
    }
}

/**
 * Drops expired or unbookable waitlist requests and, when the requested slot
 * is free, auto-books it in the waiting user's name through the standard
 * reservation save pipeline.
 *
 * @param ReservationWaitlistRequest $request
 * @param ReservationViewRepository $reservationViewRepository
 * @param UserRepository $userRepository
 * @param ReservationWaitlistRepository $waitlistRepository
 * @param ReservationPresenterFactory $presenterFactory
 */
function ProcessWaitlistRequest(
    ReservationWaitlistRequest $request,
    ReservationViewRepository $reservationViewRepository,
    UserRepository $userRepository,
    ReservationWaitlistRepository $waitlistRepository,
    ReservationPresenterFactory $presenterFactory
) {
    if ($request->StartDate()->LessThanOrEqual(Date::Now())) {
        Log::Debug('Dropping expired waitlist request. Id=%d, User=%d, Resource=%d', $request->Id(), $request->UserId(), $request->ResourceId());
        $waitlistRepository->Delete($request);
        return;
    }

    $user = $userRepository->LoadById($request->UserId());
    if ($user->Id() === null || $user->StatusId() != AccountStatus::ACTIVE) {
        Log::Debug('Dropping waitlist request for a missing or inactive user. Id=%d, User=%d', $request->Id(), $request->UserId());
        $waitlistRepository->Delete($request);
        return;
    }

    if (WaitlistRequestConflicts($reservationViewRepository, $request)) {
        return; // slot is still booked, retry on a later run
    }

    $session = (new UserSessionBuilder(new AuthorizationService($userRepository)))->buildUserSession($user, LoginTime::Now());
    $savePage = new WaitlistReservationSavePage($request, $session);

    $presenter = $presenterFactory->Create($savePage, $session);
    $reservation = $presenter->BuildReservation();
    $presenter->HandleReservation($reservation);

    if (empty($savePage->ReferenceNumber())) {
        // Booking failed (e.g. the slot was taken in the same minute): keep the
        // row in the queue and retry on the next run.
        Log::Debug('Waitlist booking failed, request stays in queue. Id=%d, Errors=%s', $request->Id(), implode('; ', $savePage->Errors()));
        return;
    }

    Log::Debug(
        'Auto-booked waitlist request. Id=%d, User=%d, Resource=%d, ReferenceNumber=%s, RequiresApproval=%s',
        $request->Id(),
        $request->UserId(),
        $request->ResourceId(),
        $savePage->ReferenceNumber(),
        $savePage->RequiresApproval() ? 'true' : 'false'
    );
    $waitlistRepository->Delete($request);
}

/**
 * Checks whether any reservation on the requested resource overlaps the
 * requested time, the same way sendwaitlist.php does.
 *
 * @param ReservationViewRepository $reservationViewRepository
 * @param ReservationWaitlistRequest $request
 * @return bool
 */
function WaitlistRequestConflicts(ReservationViewRepository $reservationViewRepository, ReservationWaitlistRequest $request)
{
    $reservations = $reservationViewRepository->GetReservations($request->StartDate(), $request->EndDate(), null, null, null, $request->ResourceId());

    /** @var ReservationItemView $reservation */
    foreach ($reservations as $reservation) {
        if ($reservation->BufferedTimes()->Overlaps($request->Duration())) {
            return true;
        }
    }

    return false;
}

Log::Debug('Running processwaitlistqueue.php');

JobCop::EnsureCommandLine();

try {
    $reservationViewRepository = new ReservationViewRepository();
    $userRepository = new UserRepository();
    $waitlistRepository = new ReservationWaitlistRepository();
    $presenterFactory = new ReservationPresenterFactory();

    // GetAll() has no ORDER BY; the auto-increment id is the FIFO join order,
    // so sort explicitly before processing.
    $waitlistRequests = $waitlistRepository->GetAll();
    usort($waitlistRequests, static fn (ReservationWaitlistRequest $a, ReservationWaitlistRequest $b) => $a->Id() <=> $b->Id());

    /** @var ReservationWaitlistRequest $request */
    foreach ($waitlistRequests as $request) {
        try {
            ProcessWaitlistRequest(
                request: $request,
                reservationViewRepository: $reservationViewRepository,
                userRepository: $userRepository,
                waitlistRepository: $waitlistRepository,
                presenterFactory: $presenterFactory
            );
        } catch (Throwable $ex) {
            // Keep the row so it is retried on the next run; one bad request
            // must not block the rest of the queue.
            Log::Error('Error processing waitlist request %d, will retry: %s', $request->Id(), $ex);
        }
    }
} catch (Exception $ex) {
    Log::Error('Error running processwaitlistqueue.php: %s', $ex);
}

Log::Debug('Finished running processwaitlistqueue.php');
