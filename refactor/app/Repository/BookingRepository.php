<?php

namespace DTApi\Repository;

use Event;
use Carbon\Carbon;
use Monolog\Logger;
use DTApi\Models\Job;
use DTApi\Models\User;
use App\Enums\JobTypeEnum;
use DTApi\Models\Language;
use DTApi\Models\UserMeta;
use App\Enums\UserTypeEnum;
use DTApi\Helpers\TeHelper;
use App\Enums\JobStatusEnum;
use DTApi\Mailers\AppMailer;
use DTApi\Models\Translator;
use Illuminate\Http\Request;
use App\Enums\UserStatusEnum;
use DTApi\Events\SessionEnded;
use DTApi\Events\JobWasCreated;
use DTApi\Models\UserLanguages;
use DTApi\Events\JobWasCanceled;
use DTApi\Helpers\SendSMSHelper;
use DTApi\Models\UsersBlacklist;
use DTApi\Helpers\DateTimeHelper;
use DTApi\Mailers\MailerInterface;
use Illuminate\Support\Facades\DB;
use Monolog\Handler\StreamHandler;
use Illuminate\Support\Facades\Log;
use Monolog\Handler\FirePHPHandler;
use Illuminate\Support\Facades\Auth;

/**
 * Class BookingRepository
 * @package DTApi\Repository
 */
class BookingRepository extends BaseRepository
{

    protected $logger;

    /**
     * @param Job $model
     */
    function __construct(protected Job $model, protected MailerInterface $mailer)
    {
        // not sure if this line below is necessary
        parent::__construct($model);

        $this->logger = new Logger('admin_logger');

        $this->logger->pushHandler(new StreamHandler(storage_path('logs/admin/laravel-' . date('Y-m-d') . '.log'), Logger::DEBUG));
        $this->logger->pushHandler(new FirePHPHandler());
    }

    /**
     * @param $user_id
     * @return array
     */
    // Use proper naming for singular or plural
    public function getUserJobs($user_id): array
    {
        $user = User::find($user_id);
        $user_type = '';
        $emergency_jobs = [];
        $normal_jobs = [];

        // null check should be done first
        if (!$user) {
            return ['emergency_jobs' => $emergency_jobs, 'normal_jobs' => $normal_jobs, 'user' => null, 'user_type' => $user_type];
        }

        // use model custom attribute
        if ($user->is_customer) {
            $jobs = $user->jobs()->with('user.userMeta', 'user.average', 'translatorJobRel.user.average', 'language', 'feedback')
                ->whereIn('status', [JobStatusEnum::PENDING, JobStatusEnum::ASSIGNED, JobStatusEnum::STARTED])
                ->orderBy('due', 'asc')
                ->get();
            $user_type = UserTypeEnum::CUSTOMER;
        } elseif ($user->is('translator')) {
            $jobs = Job::getTranslatorJobs($user->id, JobStatusEnum::NEW);
            $jobs = $jobs->pluck('jobs')->all();
            $user_type = UserTypeEnum::TRANSLATOR;
        }

        if (!empty($jobs)) {
            foreach ($jobs as $job_item) {
                // if the value is from the database, it should be boolean instead of string yes or no
                if ($job_item->immediate) {
                    $emergency_jobs[] = $job_item;
                } else {
                    $normal_jobs[] = $job_item;
                }
            }
            $normal_jobs = collect($normal_jobs)->each(function ($item, $key) use ($user_id) {
                $item['user_check'] = Job::checkParticularJob($user_id, $item);
            })->sortBy('due')->all();
        }

        return ['emergency_jobs' => $emergency_jobs, 'normal_jobs' => $normal_jobs, 'user' => $user, 'user_type' => $user_type];
    }

    /**
     * @param $user_id
     * @param Request $request
     * @return array
     */
    public function getUsersJobsHistory($user_id, Request $request): array
    {
        $user = User::find($user_id);
        if (!$user) {
            return ['emergency_jobs' => [], 'normal_jobs' => [], 'jobs' => [], 'user' => null, 'user_type' => ''];
        }

        // use default value as correct type also
        $page_num = $request->get('page', 1);
        $per_page = $request->get('per_page', 15);
        $user_type = '';
        $emergency_jobs = [];
        $normal_jobs = [];
        $jobs = [];

        if ($user->is_customer) {
            // What I done here is not refactoring but fixing the code, I think.
            $jobs = $user->jobs()
                ->with('user.userMeta', 'user.average', 'translatorJobRel.user.average', 'language', 'feedback', 'distance')
                ->whereIn('status', [JobStatusEnum::COMPLETED, JobStatusEnum::WITHDRAWBEFORE24, JobStatusEnum::WITHDRAWAFTER24, JobStatusEnum::TIMEDOUT])
                ->orderBy('due', 'desc')
                ->paginate($per_page);
            $user_type = UserTypeEnum::CUSTOMER;
            $num_pages = $jobs->lastPage();

            foreach ($jobs as $job_item) {
                if ($job_item->immediate) {
                    $emergency_jobs[] = $job_item;
                } else {
                    $normal_jobs[] = $job_item;
                }
            }

            return [
                'emergency_jobs' => $emergency_jobs, 
                'normal_jobs' => $normal_jobs, 
                'jobs' => $jobs, 
                'user' => $user, 
                'user_type' => $user_type, 
                'num_pages' => $num_pages, 
                'page_num' => $page_num
            ];
        } elseif ($user->is_translator) {
            // 'historic' in parameter is not necessary because it's already in the function name
            $jobs = Job::getTranslatorJobsHistoric($user->id, $page_num); // this returns a paginator
            $num_pages = $jobs->lastPage();

            $user_type = UserTypeEnum::TRANSLATOR;

            foreach ($jobs as $job_item) {
                if ($job_item->immediate) {
                    $emergency_jobs[] = $job_item;
                } else {
                    $normal_jobs[] = $job_item;
                }
            }

            return [
                'emergency_jobs' => $emergency_jobs, 
                'normal_jobs' => $normal_jobs, 
                'jobs' => $jobs, 
                'user' => $user, 
                'user_type' => $user_type, 
                'num_pages' => $num_pages, 
                'page_num' => $page_num
            ];
        }

        // return default value if no condition is met

        return [
            'emergency_jobs' => $emergency_jobs, 
            'normal_jobs' => $normal_jobs, 
            'jobs' => $jobs, 
            'user' => $user, 
            'user_type' => $user_type, 
            'num_pages' => 1, 
            'page_num' => $page_num
        ];
    }

    /**
     * @param User $user
     * @param $data
     * @return array
     */
    public function store(User $user, $data): array
    {

        $immediate_time = 5;
        $consumer_type = $user->userMeta->consumer_type;
        if ($user->user_type == UserTypeEnum::CUSTOMER) {
            if (!isset($data['from_language_id'])) {
                $response['is_success'] = false;
                $response['message'] = "Du måste fylla in alla fält";
                $response['field_name'] = "from_language_id";

                return $response;
            }

            if (!isset($data['immediate'])) {
                $response['is_success'] = false;
                $response['message'] = "Du måste fylla in alla fält";
                $response['field_name'] = "immediate";

                return $response;
            }

            if (!$data['immediate']) {
                if (!isset($data['due_date']) || empty($data['due_date'])) {
                    $response['is_success'] = false;
                    $response['message'] = "Du måste fylla in alla fält";
                    $response['field_name'] = "due_date";

                    return $response;
                }
                if (!isset($data['due_time']) || empty($data['due_time'])) {
                    $response['is_success'] = false;
                    $response['message'] = "Du måste fylla in alla fält";
                    $response['field_name'] = "due_time";

                    return $response;
                }
                if (!isset($data['customer_phone_type']) && !isset($data['customer_physical_type'])) {
                    $response['is_success'] = false;
                    $response['message'] = "Du måste göra ett val här";
                    $response['field_name'] = "customer_phone_type";

                    return $response;
                }
                if (!isset($data['duration']) || empty($data['duration'])) {
                    $response['is_success'] = false;
                    $response['message'] = "Du måste fylla in alla fält";
                    $response['field_name'] = "duration";

                    return $response;
                }
            } else {
                if (!isset($data['duration']) || empty($data['duration'])) {
                    $response['is_success'] = false;
                    $response['message'] = "Du måste fylla in alla fält";
                    $response['field_name'] = "duration";

                    return $response;
                }
            }

            $data['customer_phone_type'] = false;
            if (isset($data['customer_phone_type'])) {
                $data['customer_phone_type'] = true;
            }

            $data['customer_physical_type'] = false;
                $response['customer_physical_type'] = false;
            if (isset($data['customer_physical_type'])) {
                $data['customer_physical_type'] = true;
                $response['customer_physical_type'] = true;
            }

            if ($data['immediate']) {
                $due_carbon = Carbon::now()->addMinute($immediate_time);
                $data['due'] = $due_carbon->format('Y-m-d H:i:s');
                $data['immediate'] = true;
                $data['customer_phone_type'] = true;
                $response['type'] = JobTypeEnum::IMMEDIATE;
            } else {
                $due = $data['due_date'] . " " . $data['due_time'];
                $response['type'] = JobTypeEnum::REGULAR;
                $due_carbon = Carbon::createFromFormat('m/d/Y H:i', $due);
                $data['due'] = $due_carbon->format('Y-m-d H:i:s');
                if ($due_carbon->isPast()) {
                    $response['is_success'] = false;
                    $response['message'] = "Can't create booking in past";

                    return $response;
                }
            }

            if (in_array('male', $data['job_for'])) {
                $data['gender'] = 'male';
            } else if (in_array('female', $data['job_for'])) {
                $data['gender'] = 'female';
            }

            if (in_array('normal', $data['job_for'])) {
                $data['certified'] = 'normal';
            }
            else if (in_array('certified', $data['job_for'])) {
                $data['certified'] = 'yes';
            } else if (in_array('certified_in_law', $data['job_for'])) {
                $data['certified'] = 'law';
            } else if (in_array('certified_in_helth', $data['job_for'])) {
                $data['certified'] = 'health';
            }
            if (in_array('normal', $data['job_for']) && in_array('certified', $data['job_for'])) {
                $data['certified'] = 'both';
            }
            else if(in_array('normal', $data['job_for']) && in_array('certified_in_law', $data['job_for']))
            {
                $data['certified'] = 'n_law';
            }
            else if(in_array('normal', $data['job_for']) && in_array('certified_in_health', $data['job_for']))
            {
                $data['certified'] = 'n_health';
            }

            // use switch case for better readability or match for later version
            $data['job_type'] = match ($consumer_type) {
                'rws_consumer' => 'rws',
                'ngo' => 'unpaid',
                'paid' => 'paid',
                default => 'unpaid',
            };

            $data['b_created_at'] = date('Y-m-d H:i:s');
            if (isset($due)) {
                $data['will_expire_at'] = TeHelper::willExpireAt($due, $data['b_created_at']);
            }
                
            $data['by_admin'] = isset($data['by_admin']) ? $data['by_admin'] : 'no';

            $job = $user->jobs()->create($data);

            $response['is_success'] = true;
            $response['job_id'] = $job->id;
            $data['job_for'] = array();
            if ($job->gender != null) {
                if ($job->gender == 'male') {
                    $data['job_for'][] = 'Man';
                } else if ($job->gender == 'female') {
                    $data['job_for'][] = 'Woman'; // should be Woman as it's in English like Man
                }
            }

            if ($job->certified) {
                if ($job->certified == 'both') {
                    $data['job_for'][] = 'normal';
                    $data['job_for'][] = 'certified';
                } else if ($job->certified == 'yes') {
                    $data['job_for'][] = 'certified';
                } else {
                    $data['job_for'][] = $job->certified;
                }
            }

            $data['customer_town'] = $user->userMeta->city;
            $data['customer_type'] = $user->userMeta->customer_type;

            //Event::fire(new JobWasCreated(Job $job, $data, '*'));

            //$this->sendNotificationToSuitableTranslators($job->id, $data, '*');// send Push for New job posting
        } else {
            $response['is_success'] = false;
            $response['message'] = "Translator can not create booking";
        }

        return $response;

    }

    /**
     * @param $data
     * @return array
     */
    public function storeJobEmail($data): array
    {
        $user_type = $data['user_type'];
        $job = Job::findOrFail($data['user_email_job_id'] ?? 0);

        if (!$job) {
            return ['is_success' => false, 'message' => 'Job not found'];
        }

        $job->user_email = $data['user_email'] ?? '';
        $job->reference = isset($data['reference']) ? $data['reference'] : '';
        $user = $job->user()->get()->first();
        if (isset($data['address'])) {
            $job->address = ($data['address'] != '') ? $data['address'] : $user->userMeta->address;
            $job->instructions = ($data['instructions'] != '') ? $data['instructions'] : $user->userMeta->instructions;
            $job->town = ($data['town'] != '') ? $data['town'] : $user->userMeta->city;
        }
        $job->save();

        $email = $user->email;
        $name = $user->name;

        if (!empty($job->user_email)) {
            $email = $job->user_email;
        }

        $subject = 'Vi har mottagit er tolkbokning. Bokningsnr: #' . $job->id;
        $send_data = [
            'user' => $user,
            'job'  => $job
        ];
        $this->mailer->send($email, $name, $subject, 'emails.job_created', $send_data);

        $response['type'] = $user_type;
        $response['job'] = $job;
        $response['is_success'] = true;
        $data = $this->jobToData($job);
        Event::fire(new JobWasCreated(Job $job, $data, '*'));

        return $response;

    }

    /**
     * @param Job $job
     * @return array
     */
    public function jobToData(Job $job): array
    {
        $data = [];            // save job's information to data for sending Push
        $data['job_id'] = $job->id;
        $data['from_language_id'] = $job->from_language_id;
        $data['immediate'] = $job->immediate;
        $data['duration'] = $job->duration;
        $data['status'] = $job->status;
        $data['gender'] = $job->gender;
        $data['certified'] = $job->certified;
        $data['due'] = $job->due;
        $data['job_type'] = $job->job_type;
        $data['customer_phone_type'] = $job->customer_phone_type;
        $data['customer_physical_type'] = $job->customer_physical_type;
        $data['customer_town'] = $job->town;
        $data['customer_type'] = $job->user->userMeta->customer_type;

        $due_Date = explode(" ", $job->due);
        $due_date = $due_Date[0];
        $due_time = $due_Date[1];

        $data['due_date'] = $due_date;
        $data['due_time'] = $due_time;

        $data['job_for'] = [];
        if ($job->gender) {
            if ($job->gender == 'male') {
                $data['job_for'][] = 'Man';
            } else if ($job->gender == 'female') {
                $data['job_for'][] = 'Kvinna'; // not use if it us intensional
            }
        }
        if ($job->certified) {
            if ($job->certified == 'both') {
                $data['job_for'][] = 'Godkänd tolk';
                $data['job_for'][] = 'Auktoriserad';
            } else if ($job->certified == 'yes') {
                $data['job_for'][] = 'Auktoriserad';
            } else if ($job->certified == 'n_health') {
                $data['job_for'][] = 'Sjukvårdstolk';
            } else if ($job->certified == 'law' || $job->certified == 'n_law') {
                $data['job_for'][] = 'Rätttstolk';
            } else {
                $data['job_for'][] = $job->certified;
            }
        }

        return $data;
    }

    /**
     * @param array $post_data
     * @return void
     */
    public function jobEnd($post_data = []): void
    {
        $completed_date = date('Y-m-d H:i:s');
        $job_id = $post_data["job_id"];
        $job_detail = Job::with('translatorJobRel')->find($job_id);
        $due_date = $job_detail->due;
        $start = date_create($due_date);
        $end = date_create($completed_date);
        $diff = date_diff($end, $start);
        $interval = $diff->h . ':' . $diff->i . ':' . $diff->s;
        $job = $job_detail;
        $job->end_at = date('Y-m-d H:i:s');
        $job->status = 'completed';
        $job->session_time = $interval;

        $user = $job->user()->get()->first();
        if (!empty($job->user_email)) {
            $email = $job->user_email;
        } else {
            $email = $user->email;
        }
        $name = $user->name;
        $subject = 'Information om avslutad tolkning för bokningsnummer # ' . $job->id;
        $session_explode = explode(':', $job->session_time);
        $session_time = $session_explode[0] . ' tim ' . $session_explode[1] . ' min';
        $data = [
            'user'         => $user,
            'job'          => $job,
            'session_time' => $session_time,
            'for_text'     => 'faktura'
        ];
        $mailer = new AppMailer();
        $mailer->send($email, $name, $subject, 'emails.session-ended', $data);

        $job->save();

        $tr = $job->translatorJobRel->where('completed_at', Null)->where('cancel_at', Null)->first();

        Event::fire(new SessionEnded(Job $job, ($post_data['userid'] == $job->user_id) ? $tr->user_id : $job->user_id));

        $user = $tr->user()->first();
        $email = $user->email;
        $name = $user->name;
        $subject = 'Information om avslutad tolkning för bokningsnummer # ' . $job->id;
        $data = [
            'user'         => $user,
            'job'          => $job,
            'session_time' => $session_time,
            'for_text'     => 'lön'
        ];
        $mailer = new AppMailer();
        $mailer->send($email, $name, $subject, 'emails.session-ended', $data);

        $tr->completed_at = $completed_date;
        $tr->completed_by = $post_data['userid'];
        $tr->save();
    }

    /**
     * Function to get all Potential jobs of user with his ID
     * @param $user_id
     * @return array
     */
    public function getPotentialJobIdsWithUserId($user_id): array
    {
        $user_meta = UserMeta::where('user_id', $user_id)->first();
        $translator_type = $user_meta->translator_type;
        $job_type = match ($translator_type) {
            'professional' => 'paid',   // show all jobs for professionals
            'rws_translator' => 'rws',   // for rws_translator only show rws jobs
            'volunteer' => 'unpaid',    // for volunteers only show unpaid jobs
            default => 'unpaid',
        };

        $languages = UserLanguages::where('user_id', '=', $user_id)->get();
        $user_language = collect($languages)->pluck('lang_id')->all();
        $gender = $user_meta->gender;
        $translator_level = $user_meta->translator_level;
        $job_ids = Job::getJobs($user_id, $job_type, 'pending', $user_language, $gender, $translator_level);
        foreach ($job_ids as $k => $v)     // checking translator town
        {
            $job = Job::find($v->id);
            $job_user_id = $job->user_id;
            $check_town = Job::checkTowns($job_user_id, $user_id);
            if (($job->customer_phone_type == 'no' || $job->customer_phone_type == '') && $job->customer_physical_type == 'yes' && $check_town == false) {
                unset($job_ids[$k]);
            }
        }
        $jobs = TeHelper::convertJobIdsInObjs($job_ids);

        return $jobs;
    }

    /**
     * @param Job $job
     * @param array $data
     * @param $exclude_user_id
     * @return void
     */
    public function sendNotificationTranslator(Job $job, $data = [], $exclude_user_id): void
    {
        $users = User::all();
        $translator_array = [];            // suitable translators (no need to delay push)
        $delay_translator_array = [];     // suitable translators (need to delay push) // variable name spell mistake

        foreach ($users as $user) {
            if ($user->user_type == UserTypeEnum::TRANSLATOR && $user->status == UserStatusEnum::ACTIVE && $user->id != $exclude_user_id) { // user is translator and he is not disabled
                if (!$this->isNeedToSendPush($user->id)) continue;

                $not_get_emergency = TeHelper::getUserMeta($user->id, 'not_get_emergency');
                if ($data['immediate'] == 'yes' && $not_get_emergency == 'yes') continue;

                $jobs = $this->getPotentialJobIdsWithUserId($user->id); // get all potential jobs of this user
                foreach ($jobs as $_job) {
                    if ($job->id == $_job->id) { // one potential job is the same with current job
                        $user_id = $user->id;
                        $job_for_translator = Job::assignedToParticularTranslator($user_id, $_job->id);
                        if ($job_for_translator == 'SpecificJob') {
                            $job_checker = Job::checkParticularJob($user_id, $_job);
                            if (($job_checker != 'userCanNotAcceptJob')) {
                                if ($this->isNeedToDelayPush($user->id)) {
                                    $delay_translator_array[] = $user;
                                } else {
                                    $translator_array[] = $user;
                                }
                            }
                        }
                    }
                }
            }
        }

        $data['language'] = TeHelper::fetchLanguageFromJobId($data['from_language_id']);
        $data['notification_type'] = 'suitable_job';
        $msg_contents = '';
        if ($data['immediate'] == 'no') {
            $msg_contents = 'Ny bokning för ' . $data['language'] . 'tolk ' . $data['duration'] . 'min ' . $data['due'];
        } else {
            $msg_contents = 'Ny akutbokning för ' . $data['language'] . 'tolk ' . $data['duration'] . 'min';
        }
        $msg_text = array(
            "en" => $msg_contents // Are you sure it's English?
        );

        $logger = new Logger('push_logger');

        $logger->pushHandler(new StreamHandler(storage_path('logs/push/laravel-' . date('Y-m-d') . '.log'), Logger::DEBUG));
        $logger->pushHandler(new FirePHPHandler());
        $logger->addInfo('Push send for job ' . $job->id, [$translator_array, $delay_translator_array, $msg_text, $data]);
        $this->sendPushNotificationToSpecificUsers($translator_array, $job->id, $data, $msg_text, false);       // send new booking push to suitable translators(not delay)
        $this->sendPushNotificationToSpecificUsers($delay_translator_array, $job->id, $data, $msg_text, true); // send new booking push to suitable translators(need to delay)
    }

    /**
     * Sends SMS to translators and retuns count of translators
     * @param Job $job
     * @return int
     */
    public function sendSMSNotificationToTranslator($job): int
    {
        $translators = $this->getPotentialTranslators($job);
        $job_poster_meta = UserMeta::where('user_id', $job->user_id)->first();

        // prepare message templates
        $date = date('d.m.Y', strtotime($job->due));
        $time = date('H:i', strtotime($job->due));
        $duration = $this->convertToHoursMins($job->duration);
        $job_id = $job->id;
        $city = $job->city ? $job->city : $job_poster_meta->city;

        $phone_job_message_template = trans('sms.phone_job', ['date' => $date, 'time' => $time, 'duration' => $duration, 'job_id' => $job_id]);

        $physical_job_message_template = trans('sms.physical_job', ['date' => $date, 'time' => $time, 'town' => $city, 'duration' => $duration, 'job_id' => $job_id]);

        // analyse weather it's phone or physical; if both = default to phone
        $message = '';
        if ($job->customer_physical_type && !$job->customer_phone_type) {
            // It's a physical job
            $message = $physical_job_message_template;
        } else if (!$job->customer_physical_type && $job->customer_phone_type) {
            // It's a phone job
            $message = $phone_job_message_template;
        } else if ($job->customer_physical_type && $job->customer_phone_type) {
            // It's both, but should be handled as phone job
            $message = $phone_job_message_template;
        }
        Log::info($message);

        // send messages via sms handler
        foreach ($translators as $translator) {
            // send message to translator
            // don't use env and use config instead
            $status = SendSMSHelper::send(config('sms.number'), $translator->mobile, $message); // should be in queue and not blocking
            Log::info('Send SMS to ' . $translator->email . ' (' . $translator->mobile . '), status: ' . print_r($status, true));
        }

        return count($translators);
    }

    /**
     * Function to delay the push
     * @param $user_id
     * @return bool
     */
    public function isNeedToDelayPush($user_id): bool
    {
        if (!DateTimeHelper::isNightTime()) {
            return false;
        }
        $not_get_nighttime = TeHelper::getUserMeta($user_id, 'not_get_nighttime');
        if ($not_get_nighttime == 'yes') {
            return true;
        }

        return false;
    }

    /**
     * Function to check if need to send the push
     * @param $user_id
     * @return bool
     */
    public function isNeedToSendPush($user_id): bool
    {
        $not_get_notification = TeHelper::getUserMeta($user_id, 'not_get_notification');
        if ($not_get_notification == 'yes') {
            return false;
        }

        return true;
    }

    /**
     * Function to send Onesignal Push Notifications with User-Tags
     * @param $users
     * @param $job_id
     * @param $data
     * @param $msg_text
     * @param $is_need_delay
     * @return void
     */
    public function sendPushNotificationToSpecificUsers($users, $job_id, $data, $msg_text, $is_need_delay): void
    {

        $logger = new Logger('push_logger');

        $logger->pushHandler(new StreamHandler(storage_path('logs/push/laravel-' . date('Y-m-d') . '.log'), Logger::DEBUG));
        $logger->pushHandler(new FirePHPHandler());
        $logger->addInfo('Push send for job ' . $job_id, [$users, $data, $msg_text, $is_need_delay]);
        if (config('app.env') == 'prod') {
            $one_signal_app_id = config('app.prod.one_signal.app_id');
            $one_signal_rest_auth_key = sprintf("Authorization: Basic %s", config('app.prod.one_signal.api_key'));
        } else {
            $one_signal_app_id = config('app.dev.one_signal.app_id');
            $one_signal_rest_auth_key = sprintf("Authorization: Basic %s", config('app.dev.one_signal.app_key'));
        }

        $user_tags = $this->getUserTagsStringFromArray($users);

        $data['job_id'] = $job_id;
        $ios_sound = 'default';
        $android_sound = 'default';

        if ($data['notification_type'] == 'suitable_job') {
            if ($data['immediate'] == 'no') {
                $android_sound = 'normal_booking';
                $ios_sound = 'normal_booking.mp3';
            } else {
                $android_sound = 'emergency_booking';
                $ios_sound = 'emergency_booking.mp3';
            }
        }

        $fields = array(
            'app_id'         => $one_signal_app_id,
            'tags'           => json_decode($user_tags),
            'data'           => $data,
            'title'          => ['en' => config('app.name')],
            'contents'       => $msg_text,
            'ios_badgeType'  => 'Increase',
            'ios_badgeCount' => 1,
            'android_sound'  => $android_sound,
            'ios_sound'      => $ios_sound
        );
        if ($is_need_delay) {
            $next_business_time = DateTimeHelper::getNextBusinessTimeString();
            $fields['send_after'] = $next_business_time;
        }
        $fields = json_encode($fields);
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, "https://onesignal.com/api/v1/notifications");
        curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-Type: application/json', $one_signal_rest_auth_key));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);
        curl_setopt($ch, CURLOPT_HEADER, FALSE);
        curl_setopt($ch, CURLOPT_POST, TRUE);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $fields);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, FALSE);
        $response = curl_exec($ch);
        $logger->addInfo('Push send for job ' . $job_id . ' curl answer', [$response]);
        curl_close($ch);
    }

    /**
     * @param Job $job
     * @return Collection
     */
    public function getPotentialTranslators(Job $job): Collection
    {
        $job_type = $job->job_type;

        if ($job_type == 'paid')
            $translator_type = 'professional';
        else if ($job_type == 'rws')
            $translator_type = 'rws_translator';
        else if ($job_type == 'unpaid')
            $translator_type = 'volunteer';

        $job_language = $job->from_language_id;
        $gender = $job->gender;
        $translator_level = [];
        if (!empty($job->certified)) {
            if ($job->certified == 'yes' || $job->certified == 'both') {
                $translator_level[] = 'Certified';
                $translator_level[] = 'Certified with specialisation in law';
                $translator_level[] = 'Certified with specialisation in health care';
            }
            elseif($job->certified == 'law' || $job->certified == 'n_law')
            {
                $translator_level[] = 'Certified with specialisation in law';
            }
            elseif($job->certified == 'health' || $job->certified == 'n_health')
            {
                $translator_level[] = 'Certified with specialisation in health care';
            }
            else if ($job->certified == 'normal' || $job->certified == 'both') {
                $translator_level[] = 'Layman';
                $translator_level[] = 'Read Translation courses';
            }
            elseif ($job->certified == null) {
                $translator_level[] = 'Certified';
                $translator_level[] = 'Certified with specialisation in law';
                $translator_level[] = 'Certified with specialisation in health care';
                $translator_level[] = 'Layman';
                $translator_level[] = 'Read Translation courses';
            }
        }

        $blacklist = UsersBlacklist::where('user_id', $job->user_id)->get();
        $translatorsId = collect($blacklist)->pluck('translator_id')->all();
        $users = User::getPotentialUsers($translator_type, $job_language, $gender, $translator_level, $translatorsId);

        return $users;
    }

    /**
     * @param $id
     * @param $data
     * @return bool
     */
    public function updateJob($id, $data, $user): bool
    {
        $job = Job::find($id);
        
        if (is_null($job)) {
            return false;
        }

        $current_translator = $job->translatorJobRel->whereNull('cancel_at')->first();
        if (is_null($current_translator)) {
            $current_translator = $job->translatorJobRel->whereNotNull('completed_at')->first();
        }

        $log_data = [];
        $lang_changed = false;

        $changeTranslator = $this->changeTranslator($current_translator, $data, $job);
        if ($changeTranslator['translatorChanged']) {
            $log_data[] = $changeTranslator['log_data'];
        }

        $changeDue = $this->changeDue($job->due, $data['due']);
        if ($changeDue['dateChanged']) {
            $old_time = $job->due;
            $job->due = $data['due'];
            $log_data[] = $changeDue['log_data'];
        }

        if ($job->from_language_id != $data['from_language_id']) {
            $log_data[] = [
                'old_lang' => TeHelper::fetchLanguageFromJobId($job->from_language_id),
                'new_lang' => TeHelper::fetchLanguageFromJobId($data['from_language_id'])
            ];
            $old_lang = $job->from_language_id;
            $job->from_language_id = $data['from_language_id'];
            $lang_changed = true;
        }

        $changeStatus = $this->changeStatus($job, $data, $changeTranslator['translatorChanged']);
        if ($changeStatus['status_changed'])
            $log_data[] = $changeStatus['log_data'];

        $job->admin_comments = $data['admin_comments'];

        $this->logger->addInfo('USER #' . $user->id . '(' . $user->name . ')' . ' has been updated booking <a class="openjob" href="/admin/jobs/' . $id . '">#' . $id . '</a> with data:  ', $log_data);

        $job->reference = $data['reference'];

        if ($job->due <= Carbon::now()) {
            $job->save();
        } else {
            $job->save();
            if ($changeDue['dateChanged']) {
                $this->sendChangedDateNotification($job, $old_time);
            }
            if ($changeTranslator['translatorChanged']) {
                $this->sendChangedTranslatorNotification($job, $current_translator, $changeTranslator['new_translator']);
            }
            if ($lang_changed) {
                $this->sendChangedLangNotification($job, $old_lang);
            }
        }

        return true;
    }

    /**
     * @param Job $job
     * @param $data
     * @param $changed_translator
     * @return array
     */
    private function changeStatus(Job $job, $data, $changed_translator): array
    {
        $old_status = $job->status;
        $status_changed = false;

        if (empty($data['status'])) {
            $log_data = [
                'old_status' => $old_status,
                'new_status' => ''
            ];
            $status_changed = false;
            
            return ['status_changed' => $status_changed, 'log_data' => $log_data];
        }

        if ($old_status == $data['status']) {
            $log_data = [
                'old_status' => $old_status,
                'new_status' => $data['status']
            ];
            $status_changed = false;

            return ['status_changed' => $status_changed, 'log_data' => $log_data];
        }

        if ($old_status != $data['status']) {
            switch ($job->status) {
                case 'timedout':
                    $status_changed = $this->changeTimedoutStatus(Job $job, $data, $changed_translator);
                    break;
                case 'completed':
                    $status_changed = $this->changeCompletedStatus(Job $job, $data);
                    break;
                case 'started':
                    $status_changed = $this->changeStartedStatus(Job $job, $data);
                    break;
                case 'pending':
                    $status_changed = $this->changePendingStatus(Job $job, $data, $changed_translator);
                    break;
                case 'withdrawafter24':
                    $status_changed = $this->changeWithdrawafter24Status(Job $job, $data);
                    break;
                case 'assigned':
                    $status_changed = $this->changeAssignedStatus(Job $job, $data);
                    break;
                default:
                    $status_changed = false;
                    break;
            }

            if ($status_changed) {
                $log_data = [
                    'old_status' => $old_status,
                    'new_status' => $data['status']
                ];
                
                return ['status_changed' => $status_changed, 'log_data' => $log_data];
            }
        }
    }

    /**
     * @param Job $job
     * @param $data
     * @param $changed_translator
     * @return bool
     */
    private function changeTimedoutStatus(Job $job, $data, $changed_translator): bool
    {
        $old_status = $job->status;
        $job->status = $data['status'];
        $user = $job->user()->first();
        if (!empty($job->user_email)) {
            $email = $job->user_email;
        } else {
            $email = $user->email;
        }
        $name = $user->name;
        $dataEmail = [
            'user' => $user,
            'job'  => $job
        ];
        if ($data['status'] == 'pending') {
            $job->created_at = date('Y-m-d H:i:s');
            $job->email_sent = 0;
            $job->email_sent_to_virpal = 0;
            $job->save();
            $job_data = $this->jobToData($job);

            $subject = 'Vi har nu återöppnat er bokning av ' . TeHelper::fetchLanguageFromJobId($job->from_language_id) . 'tolk för bokning #' . $job->id;
            $this->mailer->send($email, $name, $subject, 'emails.job-change-status-to-customer', $dataEmail);

            $this->sendNotificationTranslator(Job $job, $job_data, '*');   // send Push all sutiable translators

            return true;
        } elseif ($changed_translator) {
            $job->save();
            $subject = 'Bekräftelse - tolk har accepterat er bokning (bokning # ' . $job->id . ')';
            $this->mailer->send($email, $name, $subject, 'emails.job_accepted', $dataEmail);

            return true;
        }

        return false;
    }

    /**
     * @param Job $job
     * @param $data
     * @return bool
     */
    private function changeCompletedStatus(Job $job, $data): bool
    {
        $job->status = $data['status'];
        if ($data['status'] == 'timedout') {
            if (empty($data['admin_comments'])) {
                return false;
            }

            $job->admin_comments = $data['admin_comments'];
        }
        $job->save();
        
        return true;
    }

    /**
     * @param Job $job
     * @param $data
     * @return bool
     */
    private function changeStartedStatus(Job $job, $data): bool
    {
        $job->status = $data['status'];
        if ($data['admin_comments'] == '') {
            return false;
        }

        $job->admin_comments = $data['admin_comments'];
        if ($data['status'] == 'completed') {
            $user = $job->user()->first();
            if ($data['session_time'] == '') {
                return false;
            }
            $interval = $data['session_time'];
            $diff = explode(':', $interval);
            $job->end_at = date('Y-m-d H:i:s');
            $job->session_time = $interval;
            $session_time = $diff[0] . ' tim ' . $diff[1] . ' min';
            if (!empty($job->user_email)) {
                $email = $job->user_email;
            } else {
                $email = $user->email;
            }
            $name = $user->name;
            $dataEmail = [
                'user'         => $user,
                'job'          => $job,
                'session_time' => $session_time,
                'for_text'     => 'faktura'
            ];

            $subject = 'Information om avslutad tolkning för bokningsnummer #' . $job->id;
            $this->mailer->send($email, $name, $subject, 'emails.session-ended', $dataEmail);

            $user = $job->translatorJobRel->where('completed_at', Null)->where('cancel_at', Null)->first();

            $email = $user->user->email;
            $name = $user->user->name;
            $subject = 'Information om avslutad tolkning för bokningsnummer # ' . $job->id;
            $dataEmail = [
                'user'         => $user,
                'job'          => $job,
                'session_time' => $session_time,
                'for_text'     => 'lön'
            ];
            $this->mailer->send($email, $name, $subject, 'emails.session-ended', $dataEmail);

        }
        $job->save();

        return true;
    }

    /**
     * @param Job $job
     * @param $data
     * @param $changed_translator
     * @return bool
     */
    private function changePendingStatus(Job $job, $data, $changed_translator): bool
    {
        $job->status = $data['status'];
        if ($data['admin_comments'] == '' && $data['status'] == 'timedout') {
            return false;
        }
        $job->admin_comments = $data['admin_comments'];
        $user = $job->user()->first();
        if (!empty($job->user_email)) {
            $email = $job->user_email;
        } else {
            $email = $user->email;
        }
        $name = $user->name;
        $dataEmail = [
            'user' => $user,
            'job'  => $job
        ];

        if ($data['status'] == 'assigned' && $changed_translator) {
            $job->save();
            $job_data = $this->jobToData($job);

            $subject = 'Bekräftelse - tolk har accepterat er bokning (bokning # ' . $job->id . ')';
            $this->mailer->send($email, $name, $subject, 'emails.job-accepted', $dataEmail);

            $translator = Job::getJobsAssignedTranslatorDetail($job);
            $this->mailer->send($translator->email, $translator->name, $subject, 'emails.job-changed-translator-new-translator', $dataEmail);

            $language = TeHelper::fetchLanguageFromJobId($job->from_language_id);

            $this->sendSessionStartRemindNotification($user, $job, $language, $job->due, $job->duration);
            $this->sendSessionStartRemindNotification($translator, $job, $language, $job->due, $job->duration);
            return true;
        } else {
            $subject = 'Avbokning av bokningsnr: #' . $job->id;
            $this->mailer->send($email, $name, $subject, 'emails.status-changed-from-pending-or-assigned-customer', $dataEmail);
            $job->save();
            return true;
        }

        return false;
    }

    /*
     * TODO remove method and add service for notification
     * TEMP method
     * send session start remind notification
     */
    /**
     * Sends a session start reminder notification to the user.
     *
     * @param User $user The user to notify.
     * @param Job $job The job associated with the session.
     * @param string $language The language of the notification.
     * @param \DateTime $due The due date and time of the session.
     * @param int $duration The duration of the session in minutes.
     *
     * @return void
     */
    public function sendSessionStartRemindNotification(User $user, Job $job, $language, $due, $duration): void
    {

        $this->logger->pushHandler(new StreamHandler(storage_path('logs/cron/laravel-' . date('Y-m-d') . '.log'), Logger::DEBUG));
        $this->logger->pushHandler(new FirePHPHandler());
        $data = [];
        $data['notification_type'] = 'session_start_remind';
        $due_explode = explode(' ', $due);
        if ($job->customer_physical_type == 'yes')
            $msg_text = [
                "en" => 'Detta är en påminnelse om att du har en ' . $language . 'tolkning (på plats i ' . $job->town . ') kl ' . $due_explode[1] . ' på ' . $due_explode[0] . ' som vara i ' . $duration . ' min. Lycka till och kom ihåg att ge feedback efter utförd tolkning!'
            ];
        else
            $msg_text = [
                "en" => 'Detta är en påminnelse om att du har en ' . $language . 'tolkning (telefon) kl ' . $due_explode[1] . ' på ' . $due_explode[0] . ' som vara i ' . $duration . ' min.Lycka till och kom ihåg att ge feedback efter utförd tolkning!'
            ];

        if ($this->bookingRepository->isNeedToSendPush($user->id)) {
            $users_array = [$user];
            $this->bookingRepository->sendPushNotificationToSpecificUsers($users_array, $job->id, $data, $msg_text, $this->bookingRepository->isNeedToDelayPush($user->id));
            $this->logger->addInfo('sendSessionStartRemindNotification ', ['job' => $job->id]);
        }
    }

    /**
     * @param Job $job
     * @param $data
     * @return bool
     */
    private function changeWithdrawafter24Status(Job $job, $data): bool
    {
        if (in_array($data['status'], ['timedout'])) {
            $job->status = $data['status'];
            if ($data['admin_comments'] == '') {
                return false;
            }
            $job->admin_comments = $data['admin_comments'];
            $job->save();

            return true;
        }

        return false;
    }

    /**
     * @param Job $job
     * @param $data
     * @return bool
     */
    private function changeAssignedStatus(Job $job, $data): bool
    {
        if (in_array($data['status'], [JobStatusEnum::WITHDRAWBEFORE24, JobStatusEnum::WITHDRAWAFTER24, JobStatusEnum::TIMEDOUT])) {
            $job->status = $data['status'];
            if (empty($data['admin_comments']) && $data['status'] == JobStatusEnum::TIMEDOUT) {
                return false;
            }
            $job->admin_comments = $data['admin_comments'];
            if (in_array($data['status'], ['withdrawbefore24', 'withdrawafter24'])) {
                $user = $job->user()->first();

                if (!empty($job->user_email)) {
                    $email = $job->user_email;
                } else {
                    $email = $user->email;
                }
                $name = $user->name;
                $dataEmail = [
                    'user' => $user,
                    'job'  => $job
                ];

                $subject = 'Information om avslutad tolkning för bokningsnummer #' . $job->id;
                $this->mailer->send($email, $name, $subject, 'emails.status-changed-from-pending-or-assigned-customer', $dataEmail);

                $user = $job->translatorJobRel->where('completed_at', Null)->where('cancel_at', Null)->first();

                $email = $user->user->email;
                $name = $user->user->name;
                $subject = 'Information om avslutad tolkning för bokningsnummer # ' . $job->id;
                $dataEmail = [
                    'user' => $user,
                    'job'  => $job
                ];
                $this->mailer->send($email, $name, $subject, 'emails.job-cancel-translator', $dataEmail);
            }
            $job->save();

            return true;
        }

        return false;
    }

    /**
     * @param $current_translator
     * @param $data
     * @param Job $job
     * @return array
     */
    private function changeTranslator($current_translator, $data, Job $job): array
    {
        $translator_changed = false;

        if (!is_null($current_translator) || (isset($data['translator']) && $data['translator'] != 0) || $data['translator_email'] != '') {
            $log_data = [];
            if (!is_null($current_translator) && ((isset($data['translator']) && $current_translator->user_id != $data['translator']) || $data['translator_email'] != '') && (isset($data['translator']) && $data['translator'] != 0)) {
                if ($data['translator_email'] != '') {
                    $data['translator'] = User::where('email', $data['translator_email'])->first()->id;
                }
                $new_translator = $current_translator->toArray();
                $new_translator['user_id'] = $data['translator'];
                unset($new_translator['id']);
                $new_translator = Translator::create($new_translator);
                $current_translator->cancel_at = Carbon::now();
                $current_translator->save();
                $log_data[] = [
                    'old_translator' => $current_translator->user->email,
                    'new_translator' => $new_translator->user->email
                ];
                $translator_changed = true;
            } elseif (is_null($current_translator) && isset($data['translator']) && ($data['translator'] != 0 || $data['translator_email'] != '')) {
                if ($data['translator_email'] != '') {
                    $data['translator'] = User::where('email', $data['translator_email'])->first()->id;
                }
                $new_translator = Translator::create(['user_id' => $data['translator'], 'job_id' => $job->id]);
                $log_data[] = [
                    'old_translator' => null,
                    'new_translator' => $new_translator->user->email
                ];
                $translator_changed = true;
            }
            if ($translator_changed) {
                return ['translatorChanged' => $translator_changed, 'new_translator' => $new_translator, 'log_data' => $log_data];
            }
        }

        return ['translatorChanged' => $translator_changed];
    }

    /**
     * @param $old_due
     * @param $new_due
     * @return array
     */
    private function changeDue($old_due, $new_due): array
    {
        $date_changed = false;
        if ($old_due != $new_due) {
            $log_data = [
                'old_due' => $old_due,
                'new_due' => $new_due
            ];
            $date_changed = true;

            return ['dateChanged' => $date_changed, 'log_data' => $log_data];
        }

        return ['dateChanged' => $date_changed];

    }

    /**
     * @param Job $job
     * @param $current_translator
     * @param $new_translator
     * @return void
     */
    public function sendChangedTranslatorNotification(Job $job, $current_translator, $new_translator): void
    {
        $user = $job->user()->first();
        if (!empty($job->user_email)) {
            $email = $job->user_email;
        } else {
            $email = $user->email;
        }
        $name = $user->name;
        $subject = 'Meddelande om tilldelning av tolkuppdrag för uppdrag # ' . $job->id . ')';
        $data = [
            'user' => $user,
            'job'  => $job
        ];
        $this->mailer->send($email, $name, $subject, 'emails.job-changed-translator-customer', $data);
        if ($current_translator) {
            $user = $current_translator->user;
            $name = $user->name;
            $email = $user->email;
            $data['user'] = $user;

            $this->mailer->send($email, $name, $subject, 'emails.job-changed-translator-old-translator', $data);
        }

        $user = $new_translator->user;
        $name = $user->name;
        $email = $user->email;
        $data['user'] = $user;

        $this->mailer->send($email, $name, $subject, 'emails.job-changed-translator-new-translator', $data);

    }

    /**
     * @param Job $job
     * @param $old_time
     * @return void
     */
    public function sendChangedDateNotification(Job $job, $old_time): void
    {
        $user = $job->user()->first();
        if (!empty($job->user_email)) {
            $email = $job->user_email;
        } else {
            $email = $user->email;
        }
        $name = $user->name;
        $subject = 'Meddelande om ändring av tolkbokning för uppdrag # ' . $job->id . '';
        $data = [
            'user'     => $user,
            'job'      => $job,
            'old_time' => $old_time
        ];
        $this->mailer->send($email, $name, $subject, 'emails.job-changed-date', $data);

        $translator = Job::getJobsAssignedTranslatorDetail($job);
        $data = [
            'user'     => $translator,
            'job'      => $job,
            'old_time' => $old_time
        ];
        $this->mailer->send($translator->email, $translator->name, $subject, 'emails.job-changed-date', $data);
    }

    /**
     * @param Job $job
     * @param $old_lang
     * @return void
     */
    public function sendChangedLangNotification(Job $job, $old_lang): void
    {
        $user = $job->user()->first();
        if (!empty($job->user_email)) {
            $email = $job->user_email;
        } else {
            $email = $user->email;
        }
        $name = $user->name;
        $subject = 'Meddelande om ändring av tolkbokning för uppdrag # ' . $job->id . '';
        $data = [
            'user'     => $user,
            'job'      => $job,
            'old_lang' => $old_lang
        ];
        $this->mailer->send($email, $name, $subject, 'emails.job-changed-lang', $data);
        $translator = Job::getJobsAssignedTranslatorDetail($job);
        $this->mailer->send($translator->email, $translator->name, $subject, 'emails.job-changed-date', $data);
    }

    /**
     * Function to send Job Expired Push Notification
     * @param Job $job
     * @param User $user
     * @return void
     */
    public function sendExpiredNotification(Job $job, User $user): void
    {
        $data = [];
        $data['notification_type'] = 'job_expired';
        $language = TeHelper::fetchLanguageFromJobId($job->from_language_id);
        $msg_text = array(
            "en" => 'Tyvärr har ingen tolk accepterat er bokning: (' . $language . ', ' . $job->duration . 'min, ' . $job->due . '). Vänligen pröva boka om tiden.'
        );

        if ($this->isNeedToSendPush($user->id)) {
            $users_array = [$user];
            $this->sendPushNotificationToSpecificUsers($users_array, $job->id, $data, $msg_text, $this->isNeedToDelayPush($user->id));
        }
    }

    /**
     * Function to send the notification for sending the admin job cancel
     * @param $job_id
     * @return void
     */
    public function sendNotificationByAdminCancelJob($job_id): void
    {
        $job = Job::findOrFail($job_id);
        $user_meta = $job->user->userMeta()->first();
        $data = [];            // save job's information to data for sending Push
        $data['job_id'] = $job->id;
        $data['from_language_id'] = $job->from_language_id;
        $data['immediate'] = $job->immediate;
        $data['duration'] = $job->duration;
        $data['status'] = $job->status;
        $data['gender'] = $job->gender;
        $data['certified'] = $job->certified;
        $data['due'] = $job->due;
        $data['job_type'] = $job->job_type;
        $data['customer_phone_type'] = $job->customer_phone_type;
        $data['customer_physical_type'] = $job->customer_physical_type;
        $data['customer_town'] = $user_meta->city;
        $data['customer_type'] = $user_meta->customer_type;

        $due_Date = explode(" ", $job->due);
        $due_date = $due_Date[0];
        $due_time = $due_Date[1];
        $data['due_date'] = $due_date;
        $data['due_time'] = $due_time;
        $data['job_for'] = [];
        if ($job->gender != null) {
            if ($job->gender == 'male') {
                $data['job_for'][] = 'Man';
            } else if ($job->gender == 'female') {
                $data['job_for'][] = 'Kvinna';
            }
        }
        if ($job->certified != null) {
            if ($job->certified == 'both') {
                $data['job_for'][] = 'normal';
                $data['job_for'][] = 'certified';
            } else if ($job->certified == 'yes') {
                $data['job_for'][] = 'certified';
            } else {
                $data['job_for'][] = $job->certified;
            }
        }
        $this->sendNotificationTranslator($job, $data, '*');   // send Push all sutiable translators
    }

    /**
     * send session start remind notificatio
     * @param User $user
     * @param Job $job
     * @param $language
     * @param $due
     * @param $duration
     * @return void
     */
    private function sendNotificationChangePending(User $user, Job $job, $language, $due, $duration): void
    {
        $data = [];
        $data['notification_type'] = 'session_start_remind';
        if ($job->customer_physical_type == 'yes')
            $msg_text = array(
                "en" => 'Du har nu fått platstolkningen för ' . $language . ' kl ' . $duration . ' den ' . $due . '. Vänligen säkerställ att du är förberedd för den tiden. Tack!'
            );
        else
            $msg_text = array(
                "en" => 'Du har nu fått telefontolkningen för ' . $language . ' kl ' . $duration . ' den ' . $due . '. Vänligen säkerställ att du är förberedd för den tiden. Tack!'
            );

        if ($this->bookingRepository->isNeedToSendPush($user->id)) {
            $users_array = [$user];
            $this->bookingRepository->sendPushNotificationToSpecificUsers($users_array, $job->id, $data, $msg_text, $this->bookingRepository->isNeedToDelayPush($user->id));
        }
    }

    /**
     * making user_tags string from users array for creating onesignal notifications
     * @param $users
     * @return string
     */
    private function getUserTagsStringFromArray($users): string
    {
        $user_tags = "[";
        $first = true;
        foreach ($users as $user) {
            if ($first) {
                $first = false;
            } else {
                $user_tags .= ',{"operator": "OR"},';
            }
            $user_tags .= '{"key": "email", "relation": "=", "value": "' . strtolower($user->email) . '"}';
        }
        $user_tags .= ']';

        return $user_tags;
    }

    /**
     * @param $data
     * @param $user
     * @return array
     */
    public function acceptJob($data, $user): array
    {
        $admin_email = config('app.admin_email');
        $admin_sender_email = config('app.admin_sender_email');

        $user = $user;
        $job_id = $data['job_id'];
        $job = Job::findOrFail($job_id);

        if (!$job) {
            return ['is_success' => false, 'message' => 'Job not found'];
        }

        if (!Job::isTranslatorAlreadyBooked($job_id, $user->id, $job->due)) {
            if ($job->status == 'pending' && Job::insertTranslatorJobRel($user->id, $job_id)) {
                $job->status = 'assigned';
                $job->save();
                $user = $job->user()->get()->first();
                $mailer = new AppMailer();

                if (!empty($job->user_email)) {
                    $email = $job->user_email;
                    $name = $user->name;
                    $subject = 'Bekräftelse - tolk har accepterat er bokning (bokning # ' . $job->id . ')';
                } else {
                    $email = $user->email;
                    $name = $user->name;
                    $subject = 'Bekräftelse - tolk har accepterat er bokning (bokning # ' . $job->id . ')';
                }
                $data = [
                    'user' => $user,
                    'job'  => $job
                ];
                $mailer->send($email, $name, $subject, 'emails.job-accepted', $data);
            }
            /*@todo
                add flash message here.
            */
            $jobs = $this->getPotentialJobs($user);
            $response = [];
            $response['list'] = json_encode(['jobs' => $jobs, 'job' => $job], true);
            $response['is_success'] = true;
        } else {
            $response['is_success'] = false;
            $response['message'] = 'Du har redan en bokning den tiden! Bokningen är inte accepterad.';
        }

        return $response;
    }

    /**
     * Function to accept job with id
     * 
     * @param $job_id
     * @param User $user
     * @return array
     */
    public function acceptJobWithId($job_id, User $user)
    {
        $admin_email = config('app.admin_email');
        $admin_sender_email = config('app.admin_sender_email');
        $job = Job::findOrFail($job_id);

        if (!$job) {
            return ['is_success' => false, 'message' => 'Job not found'];
        }

        $response = [];

        if (!Job::isTranslatorAlreadyBooked($job_id, $user->id, $job->due)) {
            if ($job->status == 'pending' && Job::insertTranslatorJobRel($user->id, $job_id)) {
                $job->status = 'assigned';
                $job->save();
                $user = $job->user()->get()->first();
                $mailer = new AppMailer();

                if (!empty($job->user_email)) {
                    $email = $job->user_email;
                    $name = $user->name;
                } else {
                    $email = $user->email;
                    $name = $user->name;
                }
                $subject = 'Bekräftelse - tolk har accepterat er bokning (bokning # ' . $job->id . ')';
                $data = [
                    'user' => $user,
                    'job'  => $job
                ];
                $mailer->send($email, $name, $subject, 'emails.job-accepted', $data);

                $data = [];
                $data['notification_type'] = 'job_accepted';
                $language = TeHelper::fetchLanguageFromJobId($job->from_language_id);
                $msg_text = array(
                    "en" => 'Din bokning för ' . $language . ' translators, ' . $job->duration . 'min, ' . $job->due . ' har accepterats av en tolk. Vänligen öppna appen för att se detaljer om tolken.'
                );
                if ($this->isNeedToSendPush($user->id)) {
                    $users_array = [$user];
                    $this->sendPushNotificationToSpecificUsers($users_array, $job_id, $data, $msg_text, $this->isNeedToDelayPush($user->id));
                }
                // Your Booking is accepted sucessfully
                $response['is_success'] = true;
                $response['list']['job'] = $job;
                $response['message'] = 'Du har nu accepterat och fått bokningen för ' . $language . 'tolk ' . $job->duration . 'min ' . $job->due;
            } else {
                // Booking already accepted by someone else
                $language = TeHelper::fetchLanguageFromJobId($job->from_language_id);
                $response['is_success'] = false;
                $response['message'] = 'Denna ' . $language . 'tolkning ' . $job->duration . 'min ' . $job->due . ' har redan accepterats av annan tolk. Du har inte fått denna tolkning';
            }
        } else {
            // You already have a booking the time
            $response['is_success'] = false;
            $response['message'] = 'Du har redan en bokning den tiden ' . $job->due . '. Du har inte fått denna tolkning';
        }

        return $response;
    }

    /**
     * Function to cancel job
     * 
     * @param $data
     * @param User $user
     * @return array
     */
    public function cancelJobAjax($data, User $user): array
    {
        /*@todo
            add 24hrs loging here.
            If the cancelation is before 24 hours before the booking tie - supplier will be informed. Flow ended
            if the cancelation is within 24
            if cancelation is within 24 hours - translator will be informed AND the customer will get an addition to his number of bookings - so we will charge of it if the cancelation is within 24 hours
            so we must treat it as if it was an executed session
        */
        $job_id = $data['job_id'];
        $job = Job::findOrFail($job_id);

        if (!$job) {
            return ['is_success' => false, 'message' => 'Job not found'];
        }

        $response = [];

        $translator = Job::getJobsAssignedTranslatorDetail($job);
        if ($user->is('customer')) {
            $job->withdraw_at = Carbon::now();
            if ($job->withdraw_at->diffInHours($job->due) >= 24) {
                $job->status = 'withdrawbefore24';
                $response['jobstatus'] = 'success';
            } else {
                $job->status = 'withdrawafter24';
                $response['jobstatus'] = 'success';
            }
            $job->save();
            Event::fire(new JobWasCanceled($job));
            $response['is_success'] = true;
            $response['jobstatus'] = 'success';
            if ($translator) {
                $data = [];
                $data['notification_type'] = 'job_cancelled';
                $language = TeHelper::fetchLanguageFromJobId($job->from_language_id);
                $msg_text = array(
                    "en" => 'Kunden har avbokat bokningen för ' . $language . 'tolk, ' . $job->duration . 'min, ' . $job->due . '. Var god och kolla dina tidigare bokningar för detaljer.'
                );
                if ($this->isNeedToSendPush($translator->id)) {
                    $users_array = array($translator);
                    $this->sendPushNotificationToSpecificUsers($users_array, $job_id, $data, $msg_text, $this->isNeedToDelayPush($translator->id));// send Session Cancel Push to Translaotor
                }
            }
        } else {
            if ($job->due->diffInHours(Carbon::now()) > 24) {
                $customer = $job->user()->get()->first();
                if ($customer) {
                    $data = [];
                    $data['notification_type'] = 'job_cancelled';
                    $language = TeHelper::fetchLanguageFromJobId($job->from_language_id);
                    $msg_text = array(
                        "en" => 'Er ' . $language . 'tolk, ' . $job->duration . 'min ' . $job->due . ', har avbokat tolkningen. Vi letar nu efter en ny tolk som kan ersätta denne. Tack.'
                    );
                    if ($this->isNeedToSendPush($customer->id)) {
                        $users_array = array($customer);
                        $this->sendPushNotificationToSpecificUsers($users_array, $job_id, $data, $msg_text, $this->isNeedToDelayPush($customer->id));     // send Session Cancel Push to customer
                    }
                }
                $job->status = 'pending';
                $job->created_at = date('Y-m-d H:i:s');
                $job->will_expire_at = TeHelper::willExpireAt($job->due, date('Y-m-d H:i:s'));
                $job->save();
                Job::deleteTranslatorJobRel($translator->id, $job_id);

                $data = $this->jobToData($job);

                $this->sendNotificationTranslator($job, $data, $translator->id);   // send Push all sutiable translators
                $response['is_success'] = true;
            } else {
                $response['is_success'] = false;
                $response['message'] = 'Du kan inte avboka en bokning som sker inom 24 timmar genom DigitalTolk. Vänligen ring på +46 73 75 86 865 och gör din avbokning over telefon. Tack!';
            }
        }

        return $response;
    }

    /**
     * Function to get potential jobs for translator
     * 
     * @param User $user
     * @return array
     */
    public function getPotentialJobs(User $user): array
    {
        $user_meta = $user->userMeta;

        $job_type = match ($user_meta->translator_type) {
            'professional' => 'paid',  /*show all jobs for professionals.*/
            'rwstranslator' => 'rws', /* for rwstranslator only show rws jobs. */
            'volunteer' => 'unpaid', /* for volunteers only show unpaid jobs. */
            default => 'unpaid',
        };

        $languages = UserLanguages::where('user_id', '=', $user->id)->get();
        $user_language = collect($languages)->pluck('lang_id')->all();
        $gender = $user_meta->gender;
        $translator_level = $user_meta->translator_level;
        /*Call the town function for checking if the job physical, then translators in one town can get job*/
        $jobs = Job::getJobs($user->id, $job_type, 'pending', $user_language, $gender, $translator_level);
        foreach ($jobs as $k => $job) {
            $job_user_id = $job->user_id;
            $job->specific_job = Job::assignedToParticularTranslator($user->id, $job->id);
            $job->check_particular_job = Job::checkParticularJob($user->id, $job);
            $check_town = Job::checkTowns($job_user_id, $user->id);

            if($job->specific_job == 'SpecificJob')
                if ($job->check_particular_job == 'userCanNotAcceptJob')
                unset($jobs[$k]);

            if (($job->customer_phone_type == 'no' || $job->customer_phone_type == '') && $job->customer_physical_type == 'yes' && $check_town == false) {
                unset($jobs[$k]);
            }
        }

        return $jobs;
    }

    /**
     * Function to end job
     * 
     * @param array $post_data
     * @return array
     */
    public function endJob($post_data): array
    {
        $completed_date = date('Y-m-d H:i:s');
        $job_id = $post_data["job_id"];
        $job_detail = Job::with('translatorJobRel')->find($job_id);

        if($job_detail->status != 'started')
            return ['status' => 'success'];

        $due_date = $job_detail->due;
        $start = date_create($due_date);
        $end = date_create($completed_date);
        $diff = date_diff($end, $start);
        $interval = $diff->h . ':' . $diff->i . ':' . $diff->s;
        $job = $job_detail;
        $job->end_at = date('Y-m-d H:i:s');
        $job->status = 'completed';
        $job->session_time = $interval;

        $user = $job->user()->get()->first();
        if (!empty($job->user_email)) {
            $email = $job->user_email;
        } else {
            $email = $user->email;
        }
        $name = $user->name;
        $subject = 'Information om avslutad tolkning för bokningsnummer # ' . $job->id;
        $session_explode = explode(':', $job->session_time);
        $session_time = $session_explode[0] . ' tim ' . $session_explode[1] . ' min';
        $data = [
            'user'         => $user,
            'job'          => $job,
            'session_time' => $session_time,
            'for_text'     => 'faktura'
        ];
        $mailer = new AppMailer();
        $mailer->send($email, $name, $subject, 'emails.session-ended', $data);

        $job->save();

        $tr = $job->translatorJobRel()->where('completed_at', Null)->where('cancel_at', Null)->first();

        Event::fire(new SessionEnded($job, ($post_data['user_id'] == $job->user_id) ? $tr->user_id : $job->user_id));

        $user = $tr->user()->first();
        $email = $user->email;
        $name = $user->name;
        $subject = 'Information om avslutad tolkning för bokningsnummer # ' . $job->id;
        $data = [
            'user'         => $user,
            'job'          => $job,
            'session_time' => $session_time,
            'for_text'     => 'lön'
        ];
        $mailer = new AppMailer();
        $mailer->send($email, $name, $subject, 'emails.session-ended', $data);

        $tr->completed_at = $completed_date;
        $tr->completed_by = $post_data['user_id'];
        $tr->save();
        $response['is_success'] = true;

        return $response;
    }

    /**
     * Function to end job
     * 
     * @param $post_data
     * @return array
     */
    public function customerNotCall($post_data): array
    {
        $completed_date = date('Y-m-d H:i:s');
        $job_id = $post_data["job_id"];
        $job_detail = Job::with('translatorJobRel')->find($job_id);
        $due_date = $job_detail->due;
        $start = date_create($due_date);
        $end = date_create($completed_date);
        $diff = date_diff($end, $start);
        $interval = $diff->h . ':' . $diff->i . ':' . $diff->s;
        $job = $job_detail;
        $job->end_at = date('Y-m-d H:i:s');
        $job->status = 'not_carried_out_customer';

        $tr = $job->translatorJobRel()->where('completed_at', Null)->where('cancel_at', Null)->first();
        $tr->completed_at = $completed_date;
        $tr->completed_by = $tr->user_id;
        $job->save();
        $tr->save();
        $response['is_success'] = true;

        return $response;
    }

    /**
     * Function to get all jobs
     * 
     * @param Request $request
     * @param $limit
     * @return array
     */
    public function getAll(Request $request, $limit = null): array
    {
        $request_data = $request->all();
        $user = $request->user();
        $consumer_type = $user->consumer_type;

        if ($user && $user->user_type == env('SUPERADMIN_ROLE_ID')) {
            $all_jobs = Job::query();

            if (isset($request_data['feedback']) && $request_data['feedback'] != 'false') {
                $all_jobs->where('ignore_feedback', '0');
                $all_jobs->whereHas('feedback', function ($q) {
                    $q->where('rating', '<=', '3');
                });
                if (isset($request_data['count']) && $request_data['count'] != 'false') return ['count' => $all_jobs->count()];
            }

            if (isset($request_data['id']) && $request_data['id'] != '') {
                if (is_array($request_data['id']))
                    $all_jobs->whereIn('id', $request_data['id']);
                else
                    $all_jobs->where('id', $request_data['id']);
                $request_data = array_only($request_data, ['id']);
            }

            if (isset($request_data['lang']) && $request_data['lang'] != '') {
                $all_jobs->whereIn('from_language_id', $request_data['lang']);
            }
            if (isset($request_data['status']) && $request_data['status'] != '') {
                $all_jobs->whereIn('status', $request_data['status']);
            }
            if (isset($request_data['expired_at']) && $request_data['expired_at'] != '') {
                $all_jobs->where('expired_at', '>=', $request_data['expired_at']);
            }
            if (isset($request_data['will_expire_at']) && $request_data['will_expire_at'] != '') {
                $all_jobs->where('will_expire_at', '>=', $request_data['will_expire_at']);
            }
            if (isset($request_data['customer_email']) && count($request_data['customer_email']) && $request_data['customer_email'] != '') {
                $users = DB::table('users')->whereIn('email', $request_data['customer_email'])->get();
                if ($users) {
                    $all_jobs->whereIn('user_id', collect($users)->pluck('id')->all());
                }
            }
            if (isset($request_data['translator_email']) && count($request_data['translator_email'])) {
                $users = DB::table('users')->whereIn('email', $request_data['translator_email'])->get();
                if ($users) {
                    $allJobIDs = DB::table('translator_job_rel')->whereNull('cancel_at')->whereIn('user_id', collect($users)->pluck('id')->all())->lists('job_id');
                    $all_jobs->whereIn('id', $allJobIDs);
                }
            }
            if (isset($request_data['filter_timetype']) && $request_data['filter_timetype'] == "created") {
                if (isset($request_data['from']) && $request_data['from'] != "") {
                    $all_jobs->where('created_at', '>=', $request_data["from"]);
                }
                if (isset($request_data['to']) && $request_data['to'] != "") {
                    $to = $request_data["to"] . " 23:59:00";
                    $all_jobs->where('created_at', '<=', $to);
                }
                $all_jobs->orderBy('created_at', 'desc');
            }
            if (isset($request_data['filter_timetype']) && $request_data['filter_timetype'] == "due") {
                if (isset($request_data['from']) && $request_data['from'] != "") {
                    $all_jobs->where('due', '>=', $request_data["from"]);
                }
                if (isset($request_data['to']) && $request_data['to'] != "") {
                    $to = $request_data["to"] . " 23:59:00";
                    $all_jobs->where('due', '<=', $to);
                }
                $all_jobs->orderBy('due', 'desc');
            }

            if (isset($request_data['job_type']) && $request_data['job_type'] != '') {
                $all_jobs->whereIn('job_type', $request_data['job_type']);
                /*$all_jobs->where('jobs.job_type', '=', $request_data['job_type']);*/
            }

            if (isset($request_data['physical'])) {
                $all_jobs->where('customer_physical_type', $request_data['physical']);
                $all_jobs->where('ignore_physical', 0);
            }

            if (isset($request_data['phone'])) {
                $all_jobs->where('customer_phone_type', $request_data['phone']);
                if(isset($request_data['physical']))
                $all_jobs->where('ignore_physical_phone', 0);
            }

            if (isset($request_data['flagged'])) {
                $all_jobs->where('flagged', $request_data['flagged']);
                $all_jobs->where('ignore_flagged', 0);
            }

            if (isset($request_data['distance']) && $request_data['distance'] == 'empty') {
                $all_jobs->whereDoesntHave('distance');
            }

            if(isset($request_data['salary']) &&  $request_data['salary'] == 'yes') {
                $all_jobs->whereDoesntHave('user.salaries');
            }

            if (isset($request_data['count']) && $request_data['count'] == 'true') {
                $all_jobs = $all_jobs->count();

                return ['count' => $all_jobs];
            }

            if (isset($request_data['consumer_type']) && $request_data['consumer_type'] != '') {
                $all_jobs->whereHas('user.userMeta', function($q) use ($request_data) {
                    $q->where('consumer_type', $request_data['consumer_type']);
                });
            }

            if (isset($request_data['booking_type'])) {
                if ($request_data['booking_type'] == 'physical')
                    $all_jobs->where('customer_physical_type', 'yes');
                if ($request_data['booking_type'] == 'phone')
                    $all_jobs->where('customer_phone_type', 'yes');
            }
            
            $all_jobs->orderBy('created_at', 'desc');
            $all_jobs->with('user', 'language', 'feedback.user', 'translatorJobRel.user', 'distance');

        } else {

            $all_jobs = Job::query();

            if (isset($request_data['id']) && $request_data['id'] != '') {
                $all_jobs->where('id', $request_data['id']);
                $request_data = array_only($request_data, ['id']);
            }

            if ($consumer_type == 'RWS') {
                $all_jobs->where('job_type', '=', 'rws');
            } else {
                $all_jobs->where('job_type', '=', 'unpaid');
            }
            if (isset($request_data['feedback']) && $request_data['feedback'] != 'false') {
                $all_jobs->where('ignore_feedback', '0');
                $all_jobs->whereHas('feedback', function($q) {
                    $q->where('rating', '<=', '3');
                });
                if(isset($request_data['count']) && $request_data['count'] != 'false') return ['count' => $all_jobs->count()];
            }
            
            if (isset($request_data['lang']) && $request_data['lang'] != '') {
                $all_jobs->whereIn('from_language_id', $request_data['lang']);
            }
            if (isset($request_data['status']) && $request_data['status'] != '') {
                $all_jobs->whereIn('status', $request_data['status']);
            }
            if (isset($request_data['job_type']) && $request_data['job_type'] != '') {
                $all_jobs->whereIn('job_type', $request_data['job_type']);
            }
            if (isset($request_data['customer_email']) && $request_data['customer_email'] != '') {
                $user = DB::table('users')->where('email', $request_data['customer_email'])->first();
                if ($user) {
                    $all_jobs->where('user_id', '=', $user->id);
                }
            }
            if (isset($request_data['filter_timetype']) && $request_data['filter_timetype'] == "created") {
                if (isset($request_data['from']) && $request_data['from'] != "") {
                    $all_jobs->where('created_at', '>=', $request_data["from"]);
                }
                if (isset($request_data['to']) && $request_data['to'] != "") {
                    $to = $request_data["to"] . " 23:59:00";
                    $all_jobs->where('created_at', '<=', $to);
                }
                $all_jobs->orderBy('created_at', 'desc');
            }
            if (isset($request_data['filter_timetype']) && $request_data['filter_timetype'] == "due") {
                if (isset($request_data['from']) && $request_data['from'] != "") {
                    $all_jobs->where('due', '>=', $request_data["from"]);
                }
                if (isset($request_data['to']) && $request_data['to'] != "") {
                    $to = $request_data["to"] . " 23:59:00";
                    $all_jobs->where('due', '<=', $to);
                }
                $all_jobs->orderBy('due', 'desc');
            }

            $all_jobs->orderBy('created_at', 'desc');
            $all_jobs->with('user', 'language', 'feedback.user', 'translatorJobRel.user', 'distance');

        }
        if ($limit == 'all') {
            $all_jobs = $all_jobs->get();
        } else {
            $all_jobs = $all_jobs->paginate(15);
        }

        return $all_jobs;
    }

    /**
     * @return array
     */
    // make the function name more descriptive
    public function alerts(): array
    {
        $jobs = Job::all();
        $sesJobs = [];
        $job_id = [];
        $diff = [];
        $i = 0;

        foreach ($jobs as $job) {
            $sessionTime = explode(':', $job->session_time);
            if (count($sessionTime) >= 3) {
                $diff[$i] = ($sessionTime[0] * 60) + $sessionTime[1] + ($sessionTime[2] / 60);

                if ($diff[$i] >= $job->duration) {
                    if ($diff[$i] >= $job->duration * 2) {
                        $sesJobs [$i] = $job;
                    }
                }
                $i++;
            }
        }

        foreach ($sesJobs as $job) {
            $job_id [] = $job->id;
        }

        $languages = Language::where('active', '1')->orderBy('language')->get();
        $request_data = Request::all();
        $all_customers = DB::table('users')->where('user_type', '1')->lists('email');
        $all_translators = DB::table('users')->where('user_type', '2')->lists('email');

        $user = Auth::user();
        $consumer_type = TeHelper::getUserMeta($user->id, 'consumer_type');


        if ($user && $user->is('superadmin')) {
            $all_jobs = DB::table('jobs')
                ->join('languages', 'jobs.from_language_id', '=', 'languages.id')->whereIn('jobs.id', $job_id);
            if (isset($request_data['lang']) && $request_data['lang'] != '') {
                $all_jobs->whereIn('jobs.from_language_id', $request_data['lang'])
                    ->where('jobs.ignore', 0);
                /*$all_jobs->where('jobs.from_language_id', '=', $request_data['lang']);*/
            }
            if (isset($request_data['status']) && $request_data['status'] != '') {
                $all_jobs->whereIn('jobs.status', $request_data['status'])
                    ->where('jobs.ignore', 0);
                /*$all_jobs->where('jobs.status', '=', $request_data['status']);*/
            }
            if (isset($request_data['customer_email']) && $request_data['customer_email'] != '') {
                $user = DB::table('users')->where('email', $request_data['customer_email'])->first();
                if ($user) {
                    $all_jobs->where('jobs.user_id', '=', $user->id)
                        ->where('jobs.ignore', 0);
                }
            }
            if (isset($request_data['translator_email']) && $request_data['translator_email'] != '') {
                $user = DB::table('users')->where('email', $request_data['translator_email'])->first();
                if ($user) {
                    $allJobIDs = DB::table('translator_job_rel')->where('user_id', $user->id)->lists('job_id');
                    $all_jobs->whereIn('jobs.id', $allJobIDs)
                        ->where('jobs.ignore', 0);
                }
            }
            if (isset($request_data['filter_timetype']) && $request_data['filter_timetype'] == "created") {
                if (isset($request_data['from']) && $request_data['from'] != "") {
                    $all_jobs->where('jobs.created_at', '>=', $request_data["from"])
                        ->where('jobs.ignore', 0);
                }
                if (isset($request_data['to']) && $request_data['to'] != "") {
                    $to = $request_data["to"] . " 23:59:00";
                    $all_jobs->where('jobs.created_at', '<=', $to)
                        ->where('jobs.ignore', 0);
                }
                $all_jobs->orderBy('jobs.created_at', 'desc');
            }
            if (isset($request_data['filter_timetype']) && $request_data['filter_timetype'] == "due") {
                if (isset($request_data['from']) && $request_data['from'] != "") {
                    $all_jobs->where('jobs.due', '>=', $request_data["from"])
                        ->where('jobs.ignore', 0);
                }
                if (isset($request_data['to']) && $request_data['to'] != "") {
                    $to = $request_data["to"] . " 23:59:00";
                    $all_jobs->where('jobs.due', '<=', $to)
                        ->where('jobs.ignore', 0);
                }
                $all_jobs->orderBy('jobs.due', 'desc');
            }

            if (isset($request_data['job_type']) && $request_data['job_type'] != '') {
                $all_jobs->whereIn('jobs.job_type', $request_data['job_type'])
                    ->where('jobs.ignore', 0);
                /*$all_jobs->where('jobs.job_type', '=', $request_data['job_type']);*/
            }
            $all_jobs->select('jobs.*', 'languages.language')
                ->where('jobs.ignore', 0)
                ->whereIn('jobs.id', $job_id);

            $all_jobs->orderBy('jobs.created_at', 'desc');
            $all_jobs = $all_jobs->paginate(15);
        }

        return ['allJobs' => $all_jobs, 'languages' => $languages, 'all_customers' => $all_customers, 'all_translators' => $all_translators, 'requestdata' => $request_data];
    }

    /**
     * @return array
     */
    public function userLoginFailed(): array
    {
        $throttles = Throttles::where('ignore', 0)->with('user')->paginate(15);

        return ['throttles' => $throttles];
    }

    /**
     * @return array
     */
    public function bookingExpireNoAccepted(): array
    {
        // make the active status as boolean
        $languages = Language::where('active', true)->orderBy('language')->get();
        $request_data = Request::all();
        $all_customers = DB::table('users')->where('user_type', '1')->lists('email');
        $all_translators = DB::table('users')->where('user_type', '2')->lists('email');

        $user = Auth::user();
        $consumer_type = TeHelper::getUserMeta($user->id, 'consumer_type');

        if ($user && ($user->is('superadmin') || $user->is('admin'))) {
            $all_jobs = DB::table('jobs')
                ->join('languages', 'jobs.from_language_id', '=', 'languages.id')
                ->where('jobs.ignore_expired', 0);
            if (isset($request_data['lang']) && $request_data['lang'] != '') {
                $all_jobs->whereIn('jobs.from_language_id', $request_data['lang'])
                    ->where('jobs.status', 'pending')
                    ->where('jobs.ignore_expired', 0)
                    ->where('jobs.due', '>=', Carbon::now());
                /*$all_jobs->where('jobs.from_language_id', '=', $request_data['lang']);*/
            }
            if (isset($request_data['status']) && $request_data['status'] != '') {
                $all_jobs->whereIn('jobs.status', $request_data['status'])
                    ->where('jobs.status', 'pending')
                    ->where('jobs.ignore_expired', 0)
                    ->where('jobs.due', '>=', Carbon::now());
                /*$all_jobs->where('jobs.status', '=', $request_data['status']);*/
            }
            if (isset($request_data['customer_email']) && $request_data['customer_email'] != '') {
                $user = DB::table('users')->where('email', $request_data['customer_email'])->first();
                if ($user) {
                    $all_jobs->where('jobs.user_id', '=', $user->id)
                        ->where('jobs.status', 'pending')
                        ->where('jobs.ignore_expired', 0)
                        ->where('jobs.due', '>=', Carbon::now());
                }
            }
            if (isset($request_data['translator_email']) && $request_data['translator_email'] != '') {
                $user = DB::table('users')->where('email', $request_data['translator_email'])->first();
                if ($user) {
                    $allJobIDs = DB::table('translator_job_rel')->where('user_id', $user->id)->lists('job_id');
                    $all_jobs->whereIn('jobs.id', $allJobIDs)
                        ->where('jobs.status', 'pending')
                        ->where('jobs.ignore_expired', 0)
                        ->where('jobs.due', '>=', Carbon::now());
                }
            }
            if (isset($request_data['filter_timetype']) && $request_data['filter_timetype'] == "created") {
                if (isset($request_data['from']) && $request_data['from'] != "") {
                    $all_jobs->where('jobs.created_at', '>=', $request_data["from"])
                        ->where('jobs.status', 'pending')
                        ->where('jobs.ignore_expired', 0)
                        ->where('jobs.due', '>=', Carbon::now());
                }
                if (isset($request_data['to']) && $request_data['to'] != "") {
                    $to = $request_data["to"] . " 23:59:00";
                    $all_jobs->where('jobs.created_at', '<=', $to)
                        ->where('jobs.status', 'pending')
                        ->where('jobs.ignore_expired', 0)
                        ->where('jobs.due', '>=', Carbon::now());
                }
                $all_jobs->orderBy('jobs.created_at', 'desc');
            }
            if (isset($request_data['filter_timetype']) && $request_data['filter_timetype'] == "due") {
                if (isset($request_data['from']) && $request_data['from'] != "") {
                    $all_jobs->where('jobs.due', '>=', $request_data["from"])
                        ->where('jobs.status', 'pending')
                        ->where('jobs.ignore_expired', 0)
                        ->where('jobs.due', '>=', Carbon::now());
                }
                if (isset($request_data['to']) && $request_data['to'] != "") {
                    $to = $request_data["to"] . " 23:59:00";
                    $all_jobs->where('jobs.due', '<=', $to)
                        ->where('jobs.status', 'pending')
                        ->where('jobs.ignore_expired', 0)
                        ->where('jobs.due', '>=', Carbon::now());
                }
                $all_jobs->orderBy('jobs.due', 'desc');
            }

            if (isset($request_data['job_type']) && $request_data['job_type'] != '') {
                $all_jobs->whereIn('jobs.job_type', $request_data['job_type'])
                    ->where('jobs.status', 'pending')
                    ->where('jobs.ignore_expired', 0)
                    ->where('jobs.due', '>=', Carbon::now());
                /*$all_jobs->where('jobs.job_type', '=', $request_data['job_type']);*/
            }
            $all_jobs->select('jobs.*', 'languages.language')
                ->where('jobs.status', 'pending')
                ->where('ignore_expired', 0)
                ->where('jobs.due', '>=', Carbon::now());

            $all_jobs->orderBy('jobs.created_at', 'desc');
            $all_jobs = $all_jobs->paginate(15);

        }

        return ['allJobs' => $all_jobs, 'languages' => $languages, 'all_customers' => $all_customers, 'all_translators' => $all_translators, 'requestdata' => $request_data];
    }

    /**
     * @param $id
     * @return array
     */
    public function ignoreExpiring($id): array
    {
        $job = Job::find($id);
        if (!$job) {
            return ['error', 'Job not found'];
        }
        $job->ignore = 1;
        $job->save();

        return ['success', 'Changes saved'];
    }

    /**
     * @param $id
     * @return array
     */
    public function ignoreExpired($id): array
    {
        $job = Job::find($id);
        if (!$job) {
            return ['error', 'Job not found'];
        }
        $job->ignore_expired = 1;
        $job->save();

        return ['success', 'Changes saved'];
    }

    /**
     * @param $id
     * @return array
     */
    public function ignoreThrottle($id): array
    {
        $throttle = Throttles::find($id);
        if (!$throttle) {
            return ['error', 'Throttle not found'];
        }
        $throttle->ignore = 1;
        $throttle->save();

        return ['success', 'Changes saved'];
    }

    /**
     * @param $request
     * @return array
     */
    public function reOpen($request): array
    {
        $job_id = $request['job_id'];
        $user_id = $request['user_id'];

        $job = Job::find($job_id);
        if (!$job) {
            return ['error', 'Job not found'];
        }
        $job = $job->toArray();

        $data = [];
        $data['created_at'] = date('Y-m-d H:i:s');
        $data['will_expire_at'] = TeHelper::willExpireAt($job['due'], $data['created_at']);
        $data['updated_at'] = date('Y-m-d H:i:s');
        $data['user_id'] = $user_id;
        $data['job_id'] = $job_id;
        $data['cancel_at'] = Carbon::now();

        $datareopen = [];
        $datareopen['status'] = 'pending';
        $datareopen['created_at'] = Carbon::now();
        $datareopen['will_expire_at'] = TeHelper::willExpireAt($job['due'], $datareopen['created_at']);

        if ($job['status'] != 'timedout') {
            $affectedRows = Job::where('id', '=', $job_id)->update($datareopen);
            $new_jobid = $job_id;
        } else {
            $job['status'] = 'pending';
            $job['created_at'] = Carbon::now();
            $job['updated_at'] = Carbon::now();
            $job['will_expire_at'] = TeHelper::willExpireAt($job['due'], date('Y-m-d H:i:s'));
            $job['updated_at'] = date('Y-m-d H:i:s');
            $job['cust_16_hour_email'] = 0;
            $job['cust_48_hour_email'] = 0;
            $job['admin_comments'] = 'This booking is a reopening of booking #' . $job_id;
            //$job[0]['user_email'] = $user_email;
            $affectedRows = Job::create($job);
            $new_jobid = $affectedRows['id'];
        }

        //$result = DB::table('translator_job_rel')->insertGetId($data);
        Translator::where('job_id', $job_id)->where('cancel_at', NULL)->update(['cancel_at' => $data['cancel_at']]);
        $Translator = Translator::create($data);
        if (isset($affectedRows)) {
            $this->sendNotificationByAdminCancelJob($new_jobid);

            return ["Tolk cancelled!"];
        } else {
            return ["Please try again!"];
        }
    }

    /**
     * Convert number of minutes to hour and minute variant
     * @param  int $time   
     * @param  string $format 
     * @return string         
     */
    private function convertToHoursMins($time, $format = '%02dh %02dmin'): string
    {
        if ($time < 60) {
            return $time . 'min';
        } else if ($time == 60) {
            return '1h';
        }

        $hours = floor($time / 60);
        $minutes = ($time % 60);
        
        return sprintf($format, $hours, $minutes);
    }

}