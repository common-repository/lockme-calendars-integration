<?php

declare(strict_types=1);

namespace LockmeIntegration\Plugins;

use AmeliaBooking\Domain\Collection\Collection;
use AmeliaBooking\Domain\Entity\Bookable\Service\Service;
use AmeliaBooking\Domain\Entity\Booking\Appointment\Appointment;
use AmeliaBooking\Domain\Entity\Booking\Appointment\CustomerBooking;
use AmeliaBooking\Domain\Entity\User\Provider;
use AmeliaBooking\Domain\Factory\Booking\Appointment\AppointmentFactory;
use AmeliaBooking\Domain\Services\DateTime\DateTimeService;
use AmeliaBooking\Domain\ValueObjects\DateTime\DateTimeValue;
use AmeliaBooking\Domain\ValueObjects\Json;
use AmeliaBooking\Domain\ValueObjects\Number\Float\Price;
use AmeliaBooking\Domain\ValueObjects\Number\Integer\Id;
use AmeliaBooking\Domain\ValueObjects\Number\Integer\IntegerValue;
use AmeliaBooking\Domain\ValueObjects\String\BookingStatus;
use AmeliaBooking\Domain\ValueObjects\String\Email;
use AmeliaBooking\Domain\ValueObjects\String\Name;
use AmeliaBooking\Domain\ValueObjects\String\Phone;
use AmeliaBooking\Infrastructure\Common\Container;
use AmeliaBooking\Infrastructure\Repository\Booking\Appointment\AppointmentRepository;
use AmeliaBooking\Infrastructure\Repository\Booking\Appointment\CustomerBookingRepository;
use AmeliaBooking\Infrastructure\Repository\User\ProviderRepository;
use AmeliaBooking\Infrastructure\Repository\User\UserRepository;
use DateTime;
use DateTimeZone;
use Exception;
use League\OAuth2\Client\Provider\Exception\IdentityProviderException;
use LockmeIntegration\Plugin;
use LockmeIntegration\PluginInterface;
use WP_Query;

class Amelia implements PluginInterface
{

    private ?Container $container = null;
    private array $options;
    private Plugin $plugin;

    public function __construct(Plugin $plugin)
    {
        $this->plugin = $plugin;
        $this->options = get_option('lockme_amelia') ?: [];

        if (($this->options['use'] ?? null) && $this->CheckDependencies()) {
            add_action('amelia_after_booking_added', [$this, 'AddEditAppointment']);
            add_action('amelia_after_appointment_added', [$this, 'AddEditAppointment']);
            add_action('amelia_after_booking_rescheduled', [$this, 'AddEditAppointment']);
            add_action('amelia_after_appointment_updated', [$this, 'AddEditAppointment']);
            add_action('amelia_after_appointment_status_updated', [$this, 'AddEditAppointment']);

            add_action('init', function () {
                if ($_GET['amelia_export'] ?? null) {
                    $this->ExportToLockMe();
                    wp_redirect('?page=lockme_integration&tab=amelia_plugin&amelia_exported=1');
                    exit;
                }
            }, PHP_INT_MAX);
        }
    }

    public function getPluginName(): string
    {
        return 'Amelia';
    }

    public function CheckDependencies(): bool
    {
        return
            is_plugin_active('ameliabooking/ameliabooking.php');
    }

    public function AddEditAppointment(?array $appointment): void
    {
        if (!$appointment) {
            return;
        }

        if (
            ($appointment['type'] ?? null)  === 'appointment'
            && isset($appointment['appointment'])
        ) {
            $this->AddEditAppointment($appointment['appointment']);

            return;
        }

        foreach ($appointment['bookings'] as $booking) {
            $this->AddEditBooking($appointment, $booking);
        }
    }

    public function AddEditBooking(array $appointment, array $booking): void
    {
        if (!$booking) {
            return;
        }

        $appData = $this->AppData($appointment, $booking);

        if (!$appData) {
            return;
        }

        if (!in_array($booking['status'], [BookingStatus::APPROVED, BookingStatus::PENDING])) {
            $this->Delete($appointment, $booking);

            return;
        }

        $api = $this->plugin->GetApi();
        $lockme_data = [];

        try {
            $lockme_data = $api->Reservation((int) $appData['roomid'], "ext/{$appData['extid']}");
        } catch (Exception) {
        }

        try {
            if (!$lockme_data) { //Add new
                $api->AddReservation($appData);
            } else { //Update
                $api->EditReservation((int) $appData['roomid'], "ext/{$appData['extid']}", $appData);
            }
        } catch (Exception) {
        }
    }

    public function ExportToLockMe(): void
    {
        /** @var AppointmentRepository $appointmentRepository */
        $appointmentRepository = $this->container()->get('domain.booking.appointment.repository');
        /** @var CustomerBookingRepository $bookingRepository */
        $bookingRepository = $this->container()->get('domain.booking.customerBooking.repository');

        $appointments = new Collection();

        $startDateTime = DateTimeService::getNowDateTimeObjectInUtc();

        $appointmentRepository->getFutureAppointments(
            $appointments,
            [],
            $startDateTime->format('Y-m-d H:i:s'),
            ''
        );

        foreach ($appointments->getItems() as $appointment) {
            assert($appointment instanceof Appointment);

            foreach ($appointment->getBookings()->getItems() as $booking) {
                assert($booking instanceof CustomerBooking);

                $booking = $bookingRepository->getById($booking->getId()->getValue());

                $appointment->getBookings()->addItem($booking, $booking->getId()->getValue(), true);
            }

            $this->AddEditAppointment($appointment->toArray());
        }
    }

    public function Delete(array $appointment, array $booking): void
    {
        if (defined('LOCKME_MESSAGING')) {
            return;
        }

        $appData = $this->AppData($appointment, $booking);
        if(!$appData['roomid']) {
            return;
        }

        $api = $this->plugin->GetApi();

        try {
            $api->DeleteReservation((int) $appData['roomid'], "ext/{$appData['extid']}");
        } catch (Exception) {
        }
    }

    public function RegisterSettings(): void
    {
        if (!$this->CheckDependencies()) {
            return;
        }

        register_setting('lockme-amelia', 'lockme_amelia');

        add_settings_section(
            'lockme_amelia_section',
            'Amelia plugin settings',
            static function () {
                echo '<p>Integration settings with the Amelia plugin</p>';
            },
            'lockme-amelia'
        );

        add_settings_field(
            'amelia_use',
            'Enable integration',
            function () {
                echo '<input name="lockme_amelia[use]" type="checkbox" value="1"  '.checked(1, $this->options['use'] ?? null,
                        false).' />';
            },
            'lockme-amelia',
            'lockme_amelia_section',
        );

        if (($this->options['use'] ?? null) && $this->plugin->tab === 'amelia_plugin') {
            $api = $this->plugin->GetApi();
            $rooms = [];
            if ($api) {
                try {
                    $rooms = $api->RoomList();
                } catch (IdentityProviderException) {
                }
            }

            $serviceRepository = $this->container()->get('domain.bookable.service.repository');

            $services = $serviceRepository->getAllArrayIndexedById();

            foreach ($services->getItems() as $service) {
                assert($service instanceof Service);
                add_settings_field(
                    'calendar_'.$service->getId()->getValue(),
                    'Room for '.$service->getName()->getValue(),
                    function () use ($rooms, $service) {
                        echo '<select name="lockme_amelia[calendar_'.$service->getId()->getValue().']">';
                        echo '<option value="">--select--</option>';
                        foreach ($rooms as $room) {
                            echo '<option value="'.$room['roomid'].'" '.selected(1,
                                    $room['roomid'] == $this->options['calendar_'.$service->getId()->getValue()],
                                    false).'>'.$room['room'].' ('.$room['department'].')</options>';
                        }
                        echo '</select>';
                    },
                    'lockme-amelia',
                    'lockme_amelia_section',
                );
            }
            add_settings_field(
                'export_amelia',
                'Send data to LockMe',
                static function () {
                    echo '<a href="?page=lockme_integration&tab=amelia_plugin&amelia_export=1">Click here</a> to send all reservations to the LockMe calendar. This operation should only be required once, during the initial integration.';
                },
                'lockme-amelia',
                'lockme_amelia_section',
            );
        }
    }

    public function DrawForm(): void
    {
        if (!$this->CheckDependencies()) {
            echo "<p>You don't have required plugin</p>";
            return;
        }

        if ($_GET['amelia_exported'] ?? null) {
            echo '<div class="updated">';
            echo '  <p>Bookings export completed.</p>';
            echo '</div>';
        }
        settings_fields('lockme-amelia');
        do_settings_sections('lockme-amelia');
    }

    public function GetMessage(array $message): bool
    {
        if (!($this->options['use'] ?? null) || !$this->CheckDependencies()) {
            return false;
        }

        $data = $message['data'];
        $roomid = (int) $message['roomid'];
        $lockmeId = $message['reservationid'];
        $dateTime = new DateTime(
            sprintf('%s %s', $data['date'], $data['hour']),
        );
        $extId = $data['extid'];

        $service = $this->GetService($roomid);
        if (!$service) {
            return false;
        }

        /** @var AppointmentRepository $appointmentRepository */
        $appointmentRepository = $this->container()->get('domain.booking.appointment.repository');
        /** @var CustomerBookingRepository $bookingRepository */
        $bookingRepository = $this->container()->get('domain.booking.customerBooking.repository');
        /** @var UserRepository $userRepository */
        $userRepository = $this->container()->get('domain.users.repository');
        /** @var ProviderRepository $providerRepository */
        $providerRepository = $this->container()->get('domain.users.providers.repository');

        switch ($message['action']) {
            case 'add':
                $providers = $providerRepository->getAvailable(
                    $dateTime->format('w'),
                    wp_timezone_string()
                );
                if (empty($providers)) {
                    $providers = $providerRepository->getAll()->getItems();
                }
                if (empty($providers)) {
                    echo 'No provider :(';

                    return false;
                }

                $provider = array_keys($providers)[0];

                $appointment = AppointmentFactory::create([
                    'bookingStart' => $dateTime->format('Y-m-d H:i:s'),
                    'bookingEnd' => (clone $dateTime)->modify(sprintf('+%d seconds', $service->getDuration()->getValue()))->format('Y-m-d H:i:s'),
                    'notifyParticipants' => '0',
                    'serviceId' => $service->getId()->getValue(),
                    'providerId' => $provider,
                    'status' => $data['status'] ? BookingStatus::APPROVED : BookingStatus::PENDING,
                    'bookings' => [
                        [
                            'status' => $data['status'] ? BookingStatus::APPROVED : BookingStatus::PENDING,
                            'persons' => (int) $data['people'],
                            'price' => $data['price'],
                            'customer' => [
                                'firstName' => $data['name'],
                                'lastName' => $data['surname'],
                                'email' => $data['email'],
                                'phone' => $data['phone'],
                                'note' => 'LOCKME',
                            ],
                            'info' => json_encode([
                                'firstName' => $data['name'],
                                'lastName' => $data['surname'].' (LOCKME)',
                                'email' => $data['email'],
                                'phone' => $data['phone'],
                            ]),
                            'duration' => $service->getDuration()->getValue(),
                        ]
                    ],
                ]);

                $id = $appointmentRepository->add($appointment);
                if (!$id) {
                    echo 'Error saving appolintment.';
                }

                $appointment->setId(new Id($id));
                foreach ($appointment->getBookings()->getItems() as $booking) {
                    $booking->setAppointmentId($appointment->getId());

                    if (
                        $booking->getCustomer()->getEmail()->getValue()
                        && ($customer = $userRepository->getByEmail($booking->getCustomer()->getEmail()->getValue()))
                    ) {
                        $userRepository->update($customer->getId()->getValue(), $booking->getCustomer());
                        $customerId = $customer->getId()->getValue();
                    } else {
                        $customerId = $userRepository->add($booking->getCustomer());
                    }
                    $booking->setCustomerId(new Id($customerId));

                    $bookId = $bookingRepository->add($booking);
                    $booking->setId(new Id($bookId));
                }

                try {
                    $api = $this->plugin->GetApi();
                    $api->EditReservation($roomid, (string) $lockmeId,
                        $this->plugin->AnonymizeData(['extid' => $id])
                    );
                    return true;
                } catch (Exception) {
                }
                break;
            case 'edit':
                if ($extId) {
                    $appointment = $appointmentRepository->getById((int) $extId);
                    if ($appointment) {
                        $booking = null;
                        foreach ($appointment->getBookings()->getItems() as $book) {
                            // Sorry I'm too lazy to work with this shitty
                            // collection-like poopiness.
                            // Just take first and break.

                            $booking = $bookingRepository->getById($book->getId()->getValue());

                            break;
                        }
                        if (!$booking instanceof CustomerBooking) {

                            return false;
                        }

                        $appointment->setBookingStart(
                            new DateTimeValue($dateTime)
                        );
                        $appointment->setBookingEnd(
                            new DateTimeValue(
                                (clone $dateTime)->modify(
                                    sprintf(
                                        '+%d seconds',
                                        $booking->getDuration()->getValue(),
                                    )
                                )
                            )
                        );
                        $appointment->setService($service);
                        $appointment->setServiceId($service->getId());

                        $appointmentRepository->update($appointment->getId()->getValue(), $appointment);

                        $booking->setPrice(new Price($data['price']));
                        $bookingRepository->updatePrice($booking->getId()->getValue(), $booking);

                        $booking->setStatus(new BookingStatus($data['status'] ? BookingStatus::APPROVED : BookingStatus::PENDING));
                        $booking->setPersons(new IntegerValue((int) $data['people']));

                        $bookingRepository->update($booking->getId()->getValue(), $booking);

                        $customer = $booking->getCustomer();
                        if ($customer) {
                            if ($data['email'] && $customer->getEmail()->getValue() !== $data['email']) {
                                $existingCustomer = $userRepository->getByEmail($data['email']);
                                if ($existingCustomer) {
                                    $customer = $existingCustomer;
                                    $booking->setCustomerId($customer->getId());
                                    $booking->setCustomer($customer);
                                }
                            }

                            $customer->setEmail(new Email($data['email']));
                            $customer->setFirstName(new Name($data['name']));
                            $customer->setLastName(new Name($data['surname']));
                            $customer->setPhone(new Phone($data['phone']));

                            $userRepository->update($customer->getId()->getValue(), $customer);

                            $info = $booking->getInfo();
                            if ($info) {
                                $info = json_decode($info->getValue(), true);
                                $info['firstName'] = $data['name'];
                                $info['lastName'] = $data['surname'];
                                $info['email'] = $data['email'];
                                $info['phone'] = $data['phone'];
                                $info['locale'] = $data['language'];
                            } else {
                                $info = [
                                    'firstName' => $data['name'],
                                    'lastName' => $data['surname'],
                                    'email' => $data['email'],
                                    'phone' => $data['phone'],
                                    'locale' => $data['language'],
                                ];
                            }

                            $bookingRepository->updateInfoByCustomerId(
                                $customer->getId()->getValue(),
                                json_encode($info)
                            );

                        }

                        return true;
                    }
                }
                break;
            case 'delete':
                if ($extId) {
                    $appointment = $appointmentRepository->getById((int) $extId);
                    if ($appointment) {
                        $appointmentRepository->delete($appointment->getId()->getValue());

                        foreach ($appointment->getBookings()->getItems() as $book) {
                            $bookingRepository->delete($book->getId()->getValue());
                        }

                        return true;
                    }
                }
                break;
        }

        return false;
    }

    private function GetService(int $roomid): ?Service
    {
        $serviceRepository = $this->container()->get('domain.bookable.service.repository');

        $services = $serviceRepository->getAllArrayIndexedById();

        foreach ($services->getItems() as $service) {
            assert($service instanceof Service);
            if ($this->options['calendar_'.$service->getId()->getValue()] == $roomid) {
                return $service;
            }
        }
        return null;
    }

    private function AppData(array $appointment, array $booking): array
    {
        $room = $this->options['calendar_'.$appointment['serviceId']] ?? null;
        if (!$room) {
            return [];
        }

        $dateTime = explode(' ', $appointment['bookingStart']);

        return
            $this->plugin->AnonymizeData(
                [
                    'roomid' => $room,
                    'date' => $dateTime[0],
                    'hour' => $dateTime[1],
                    'people' => $booking['persons'],
                    'pricer' => 'API',
                    'price' => $booking['price'],
                    'name' => $booking['customer']['firstName'] ?? '',
                    'surname' => $booking['customer']['lastName'] ?? '',
                    'email' => $booking['customer']['email'] ?? '',
                    'phone' => $booking['customer']['phone'] ?? '',
                    'status' => $booking['status'] === BookingStatus::APPROVED ? 1 : 0,
                    'extid' => $appointment['id']
                ]
            );
    }

    private function container(): Container
    {
        return $this->container ??= require AMELIA_PATH . '/src/Infrastructure/ContainerConfig/container.php';
    }
}