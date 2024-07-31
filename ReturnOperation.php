<?php

namespace NW\WebService\References\Operations\Notification;

use Exception;

class TsReturnOperation extends ReferencesOperation
{
    public const TYPE_NEW    = 1;
    public const TYPE_CHANGE = 2;

    /**
     * @throws Exception
     */
    public function doOperation(): array
    {
        $data = (array)$this->getRequest('data');
        $resellerId = $data['resellerId'] ?? null;
        $notificationType = (int)($data['notificationType'] ?? 0);
        $result = [
            'notificationEmployeeByEmail' => false,
            'notificationClientByEmail'   => false,
            'notificationClientBySms'     => [
                'isSent'  => false,
                'message' => '',
            ],
        ];

        if (empty($resellerId)) {
            $result['notificationClientBySms']['message'] = 'Empty resellerId';
            return $result;
        }

        if ($notificationType === 0) {
            throw new Exception('Empty notificationType', 400);
        }

        $reseller = Seller::getById((int)$resellerId);
        if ($reseller === null) {
            throw new Exception('Seller not found!', 400);
        }

        $client = Contractor::getById((int)($data['clientId'] ?? 0));
        if ($client === null || $client->type !== Contractor::TYPE_CUSTOMER || $client->Seller->id !== $resellerId) {
            throw new Exception('Client not found!', 400);
        }

        $cFullName = $client->getFullName() ?: $client->name;

        $cr = Employee::getById((int)($data['creatorId'] ?? 0));
        if ($cr === null) {
            throw new Exception('Creator not found!', 400);
        }

        $et = Employee::getById((int)($data['expertId'] ?? 0));
        if ($et === null) {
            throw new Exception('Expert not found!', 400);
        }

        $differences = '';
        if ($notificationType === self::TYPE_NEW) {
            $differences = __('NewPositionAdded', null, $resellerId);
        } elseif ($notificationType === self::TYPE_CHANGE && !empty($data['differences'])) {
            $differences = __('PositionStatusHasChanged', [
                'FROM' => Status::getName((int)($data['differences']['from'] ?? 0)),
                'TO'   => Status::getName((int)($data['differences']['to'] ?? 0)),
            ], $resellerId);
        }

        $templateData = [
            'COMPLAINT_ID'       => (int)($data['complaintId'] ?? 0),
            'COMPLAINT_NUMBER'   => (string)($data['complaintNumber'] ?? ''),
            'CREATOR_ID'         => (int)($data['creatorId'] ?? 0),
            'CREATOR_NAME'       => $cr->getFullName(),
            'EXPERT_ID'          => (int)($data['expertId'] ?? 0),
            'EXPERT_NAME'        => $et->getFullName(),
            'CLIENT_ID'          => (int)($data['clientId'] ?? 0),
            'CLIENT_NAME'        => $cFullName,
            'CONSUMPTION_ID'     => (int)($data['consumptionId'] ?? 0),
            'CONSUMPTION_NUMBER' => (string)($data['consumptionNumber'] ?? ''),
            'AGREEMENT_NUMBER'   => (string)($data['agreementNumber'] ?? ''),
            'DATE'               => (string)($data['date'] ?? ''),
            'DIFFERENCES'        => $differences,
        ];

        foreach ($templateData as $key => $tempData) {
            if (empty($tempData)) {
                throw new Exception("Template Data ({$key}) is empty!", 500);
            }
        }

        $emailFrom = getResellerEmailFrom($resellerId);
        $emails = getEmailsByPermit($resellerId, 'tsGoodsReturn');
        if (!empty($emailFrom) && !empty($emails)) {
            foreach ($emails as $email) {
                MessagesClient::sendMessage([
                    0 => [
                        'emailFrom' => $emailFrom,
                        'emailTo'   => $email,
                        'subject'   => __('complaintEmployeeEmailSubject', $templateData, $resellerId),
                        'message'   => __('complaintEmployeeEmailBody', $templateData, $resellerId),
                    ],
                ], $resellerId, NotificationEvents::CHANGE_RETURN_STATUS);
                $result['notificationEmployeeByEmail'] = true;
            }
        }

        if ($notificationType === self::TYPE_CHANGE && !empty($data['differences']['to'])) {
            if (!empty($emailFrom) && !empty($client->email)) {
                MessagesClient::sendMessage([
                    0 => [
                        'emailFrom' => $emailFrom,
                        'emailTo'   => $client->email,
                        'subject'   => __('complaintClientEmailSubject', $templateData, $resellerId),
                        'message'   => __('complaintClientEmailBody', $templateData, $resellerId),
                    ],
                ], $resellerId, $client->id, NotificationEvents::CHANGE_RETURN_STATUS, (int)($data['differences']['to'] ?? 0));
                $result['notificationClientByEmail'] = true;
            }

            if (!empty($client->mobile)) {
                $res = NotificationManager::send($resellerId, $client->id, NotificationEvents::CHANGE_RETURN_STATUS, (int)($data['differences']['to'] ?? 0), $templateData, $error);
                if ($res) {
                    $result['notificationClientBySms']['isSent'] = true;
                }
                if (!empty($error)) {
                    $result['notificationClientBySms']['message'] = $error;
                }
            }
        }

        return $result;
    }
}
