<?php

namespace DTApi\Http\Controllers;

use Response;
use DTApi\Models\Job;
use App\Enums\UserTypeEnum;
use DTApi\Models\Distance;
use Illuminate\Http\Request;
use DTApi\Repository\BookingRepository;

/**
 * Class BookingController
 * @package DTApi\Http\Controllers
 */
class BookingController extends Controller
{
    /**
     * BookingController constructor.
     * @param BookingRepository $bookingRepository
     */
    public function __construct(protected BookingRepository $bookingRepository)
    {
        // making it concise and with fewer lines
    }

    /**
     * @param Request $request
     * @return Response
     */
    public function index(Request $request): Response
    {
        // using guard clause and making it more readable and lesser lines
        $user_id = $request->get('user_id');
        if (!$user_id) {
            return response(null);
        }

        // using Auth facade to get the user type
        // Should be using Table base for roles or Enum for roles for consistency and security
        if (Auth::user()->user_type == UserTypeEnum::ADMIN || Auth::user()->user_type == UserTypeEnum::SUPERADMIN) {
            return response($this->bookingRepository->getAll($request));
        }

        return response($this->bookingRepository->getUsersJobs($user_id));
    }

    /**
     * @param $id
     * @return Response
     */
    public function show($id): Response
    {
        // This will make confusion, BookingRepository should or more related to Booking.
        // If using Job as model, then it should be JobRepository
        $job = $this->repository->with('translatorJobRel.user')->find($id);

        return response($job);
    }

    /**
     * @param Request $request
     * @return Response
     */
    public function store(Request $request): Response
    {
        $data = $request->all();

        $response = $this->repository->store($request->user(), $data);

        return response($response);

    }

    /**
     * @param $id
     * @param Request $request
     * @return Response
     */
    public function update($id, Request $request): Response
    {
        // Making it more readable
        $data = $request->all();
        $user = $request->__authenticatedUser;
        $data = array_except($data, ['_token', 'submit']);
        $response = $this->repository->updateJob($id, $data, $user);

        return response($response);
    }

    /**
     * @param Request $request
     * @return Response
     */
    public function immediateJobEmail(Request $request): Response
    {
        // use snake_case for keys like 'app.admin_email'
        // remove unnessary variables
        $data = $request->all();

        $response = $this->repository->storeJobEmail($data);

        return response($response);
    }

    /**
     * @param Request $request
     * @return Response
     */
    public function getHistory(Request $request): Response
    {
        // Add one line before return
        // return type should be consistent for API
        if($user_id = $request->get('user_id')) {
            $response = $this->repository->getUsersJobsHistory($user_id, $request);

            return response($response);
        }

        return response(null);
    }

    /**
     * @param Request $request
     * @return Response
     */
    public function acceptJob(Request $request): Response
    {
        $data = $request->all();
        $user = $request->user();

        $response = $this->repository->acceptJob($data, $user);

        return response($response);
    }

    /**
     * @param Request $request
     * @return Response
     */
    public function acceptJobWithId(Request $request): Response
    {
        $data = $request->get('job_id');
        $user = $request->user();

        $response = $this->repository->acceptJobWithId($data, $user);

        return response($response);
    }

    /**
     * @param Request $request
     * @return Response
     */
    public function cancelJob(Request $request): Response
    {
        $data = $request->all();
        $user = $request->user();

        $response = $this->repository->cancelJobAjax($data, $user);

        return response($response);
    }

    /**
     * @param Request $request
     * @return Response
     */
    public function endJob(Request $request): Response
    {
        $data = $request->all();

        $response = $this->repository->endJob($data);

        return response($response);

    }

     /**
     * @param Request $request
     * @return Response
     */
    public function customerNotCall(Request $request): Response
    {
        $data = $request->all();

        $response = $this->repository->customerNotCall($data);

        return response($response);

    }

    /**
     * @param Request $request
     * @return Response
     */
    public function getPotentialJobs(Request $request): Response
    {
        $user = $request->user();

        $response = $this->repository->getPotentialJobs($user);

        return response($response);
    }

    /**
     * @param Request $request
     * @return Response
     */
    // Function name should be more descriptive and related to the action
    public function distanceFeed(Request $request): Response
    {
        // set default values for variables or use empty() function
        // make the variable consistent
        // use boolean values for boolean variable, NOT string
        // use [] instead of array() in newer version of PHP
        $data = $request->all();

        $distance = !empty($data['distance']) ? $data['distance'] : '';;
        $time = !empty($data['time']) ? $data['time'] : '';
        $job_id = !empty($data['job_id']) ? $data['job_id'] : '';
        $session_time = !empty($data['session_time']) ? $data['session_time'] : '';
        $admin_comment = !empty($data['admin_comment']) ? $data['admin_comment'] : '';

        $flagged = !empty($data['flagged']) && $data['flagged'] == 'true';
        if ($flagged && empty($admin_comment)) {
            return response("Please, add comment", 400);
        }

        $manually_handled = !empty($data['manually_handled']) && $data['manually_handled'] == 'true';
        $by_admin = !empty($data['by_admin']) && $data['by_admin'] == 'true';

        if ($time || $distance) {
            Distance::where('job_id', $job_id)->update(['distance' => $distance, 'time' => $time]);
        }

        if ($admin_comment || $session_time || $flagged || $manually_handled || $by_admin) {
            Job::where('id', $job_id)->update(array('admin_comments' => $admin_comment, 'flagged' => $flagged, 'session_time' => $session_time, 'manually_handled' => $manually_handled, 'by_admin' => $by_admin));
        }

        return response('Record updated!');
    }

    /**
     * @param Request $request
     * @return Response
     */
    public function reOpen(Request $request): Response
    {
        $data = $request->all();
        $response = $this->repository->reOpen($data);

        return response($response);
    }

    /**
     * @param Request $request
     * @return Response
     */
    public function resendNotifications(Request $request): Response
    {
        $data = $request->all();
        $job = $this->repository->find($data['job_id']);
        $job_data = $this->repository->jobToData($job);
        $this->repository->sendNotificationTranslator($job, $job_data, '*');

        return response(['success' => 'Push sent']);
    }

    /**
     * Sends SMS to Translator
     * @param Request $request
     * @return Response
     */
    public function resendSMSNotifications(Request $request): Response
    {
        $data = $request->all();
        $job = $this->repository->find($data['job_id']);

        try {
            $this->repository->sendSMSNotificationToTranslator($job);

            return response(['success' => 'SMS sent']);
        } catch (\Exception $e) {
            return response(['error' => $e->getMessage()], 400);
        }
    }
}
