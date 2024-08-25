<?php

namespace NW\WebService\References\Operations\Notification;

class TsReturnOperation extends ReferencesOperation
{
    public const TYPE_NEW    = 1;
    public const TYPE_CHANGE = 2;

    /**
     * @throws \Exception
     */
    public function doOperation(): array
    {
        $data = (array) $this->getRequest('data');

        if (empty($data['notificationType'])) {
            throw new \Exception('Empty notificationType', 400);
        }

        if (empty($data['resellerId']) || ! ($reseller = Seller::getById((int) $data['resellerId']))) {
            throw new \Exception('Seller not found!', 400);
        }

        if (empty($data['clientId']) || ! ($client = Contractor::getById((int) $data['clientId'])) || $client->type !== Contractor::TYPE_CUSTOMER || $client->Seller->id !== $reseller->id) {
            throw new \Exception('Client not found!', 400);
        }

        if (empty($data['creatorId']) || ! ($creator = Employee::getById((int) $data['creatorId']))) {
            throw new \Exception('Creator not found!', 400);
        }

        if (empty($data['expertId']) || ! ($expert = Employee::getById((int) $data['expertId']))) {
            throw new \Exception('Expert not found!', 400);
        }

        $templateData = $this->_getTemplateData($reseller, $client, $creator, $expert, $data);

        $result = $this->_sendMessage($reseller, $client, $templateData, $data);

        return $result;
    }

    private function _getTemplateData($reseller, $client, $creator, $expert, array $data): array
    {
        $differences = '';

        if ((int) $data['notificationType'] === self::TYPE_NEW) {
            $differences = __('NewPositionAdded', null, $reseller->id);
        }
        elseif ((int) $data['notificationType'] === self::TYPE_CHANGE && ! empty($data['differences']['from']) && ! empty($data['differences']['to'])) {
            $differences = __('PositionStatusHasChanged', [
                'FROM' => Status::getName((int) $data['differences']['from']),
                'TO'   => Status::getName((int) $data['differences']['to']),
            ], $reseller->id);
        }

        $templateData = [
            'COMPLAINT_ID'       => (int) ($data['complaintId'] ?? 0),
            'COMPLAINT_NUMBER'   => (string) ($data['complaintNumber'] ?? ''),
            'CREATOR_ID'         => $creator->id,
            'CREATOR_NAME'       => $creator->getFullName(),
            'EXPERT_ID'          => $expert->id,
            'EXPERT_NAME'        => $expert->getFullName(),
            'CLIENT_ID'          => $client->id,
            'CLIENT_NAME'        => $client->getFullName() ?: $client->name,
            'CONSUMPTION_ID'     => (int) ($data['consumptionId'] ?? 0),
            'CONSUMPTION_NUMBER' => (string) ($data['consumptionNumber'] ?? ''),
            'AGREEMENT_NUMBER'   => (string) ($data['agreementNumber'] ?? ''),
            'DATE'               => (string) ($data['date'] ?? ''),
            'DIFFERENCES'        => $differences,
        ];

        /// Если хоть одна переменная для шаблона не задана, то не отправляем уведомления
        foreach ($templateData as $key => $tempData) {
            if (! $tempData) {
                throw new \Exception("Template Data ($key) is empty!", 500);
            }
        }

        return $templateData;
    }

    /*
     * Отправка сообщения клиенту.
     */
    private function _sendMessage($reseller, $client, array $templateData, array $data): array
    {
        $result = [
            'notificationEmployeeByEmail' => false,
            'notificationClientByEmail'   => false,
            'notificationClientBySms'     => [
                'isSent'  => false,
                'message' => '',
            ],
        ];

        $emailFrom = getResellerEmailFrom($reseller->id);

        if ($emailFrom) {
            /// Получаем email сотрудников из настроек
            $emails = getEmailsByPermit($reseller->id, 'tsGoodsReturn');
            foreach ($emails as $email) {
                MessagesClient::sendMessage([
                    0 => [ /// MessageTypes::EMAIL
                        'emailFrom' => $emailFrom,
                        'emailTo'   => $email,
                        'subject'   => __('complaintEmployeeEmailSubject', $templateData, $reseller->id),
                        'message'   => __('complaintEmployeeEmailBody', $templateData, $reseller->id),
                    ],
                ], $reseller->id, $client->id, NotificationEvents::CHANGE_RETURN_STATUS);
                $result['notificationEmployeeByEmail'] = true;
            }
        }

        /// Шлём клиентское уведомление, только если произошла смена статуса
        if ((int) $data['notificationType'] === self::TYPE_CHANGE && ! empty($data['differences']['to'])) {
            if ($emailFrom && ! empty($client->email)) {
                MessagesClient::sendMessage([
                    0 => [ /// MessageTypes::EMAIL
                        'emailFrom' => $emailFrom,
                        'emailTo'   => $client->email,
                        'subject'   => __('complaintClientEmailSubject', $templateData, $reseller->id),
                        'message'   => __('complaintClientEmailBody', $templateData, $reseller->id),
                    ],
                ], $reseller->id, $client->id, NotificationEvents::CHANGE_RETURN_STATUS, (int) $data['differences']['to']);
                $result['notificationClientByEmail'] = true;
            }

            if (! empty($client->mobile)) {
                $res = NotificationManager::send($reseller->id, $client->id, NotificationEvents::CHANGE_RETURN_STATUS, (int) $data['differences']['to'], $templateData, $error);
                $result['notificationClientBySms']['isSent'] = $res;
                if ($error) {
                    $result['notificationClientBySms']['message'] = $error;
                }
            }
        }

        return $result;
    }
}
