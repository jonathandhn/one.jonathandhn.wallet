<?php

use CRM_Wallet_ExtensionUtil as E;

class CRM_Wallet_Form_ManageEvent_Wallet extends CRM_Event_Form_ManageEvent {
  /**
   * @var array
   * @phpstan-var array<string, array{id: int, label: string, html_type: string, data_type: string}>
   */
  private array $walletFields = [];

  public function preProcess(): void {
    parent::preProcess();
    $this->setSelectedChild('wallet');
  }

  public function buildQuickForm(): void {
    $this->walletFields = $this->getWalletFields();

    foreach ($this->walletFields as $key => $field) {
      $elementName = 'custom_' . $field['id'];
      CRM_Core_BAO_CustomField::addQuickFormElement($this, $elementName, $field['id'], FALSE);
      if ($field['html_type'] === 'File') {
        $this->registerFileField([$elementName]);
      }
    }

    $this->setDefaults($this->getWalletDefaults());
    $this->assign('walletFields', $this->walletFields);
    $this->assign('walletCurrentImage', $this->getCurrentImageInfo());

    parent::buildQuickForm();
  }

  public function postProcess(): void {
    $params = ['id' => $this->getEventID()];
    $params['custom'] = CRM_Core_BAO_CustomField::postProcess(
        $this->getSubmittedValues(),
        $this->getEventID(),
        'Event'
    );

    CRM_Event_BAO_Event::create($params);

    parent::endPostProcess();
  }

  public function getTitle(): string {
    return E::ts('Wallet Settings');
  }

  /**
   * @return array<string, array{id:int,label:string,html_type:string,data_type:string}>
   */
  private function getWalletFields(): array {
    $fields = [];
    $customFields = \Civi\Api4\CustomField::get(FALSE)
      ->addSelect('id', 'name', 'label', 'html_type', 'data_type')
      ->addWhere('custom_group_id.name', '=', 'Wallet_Settings')
      ->addWhere('name', 'IN', ['Wallet_Image', 'Wallet_Background_Color'])
      ->addWhere('is_active', '=', TRUE)
      ->execute();

    foreach ($customFields as $field) {
      if ($field['name'] === 'Wallet_Image') {
        $fields['image'] = [
          'id' => (int) $field['id'],
          'label' => (string) $field['label'],
          'html_type' => (string) $field['html_type'],
          'data_type' => (string) $field['data_type'],
        ];
      }
      elseif ($field['name'] === 'Wallet_Background_Color') {
        $fields['color'] = [
          'id' => (int) $field['id'],
          'label' => (string) $field['label'],
          'html_type' => (string) $field['html_type'],
          'data_type' => (string) $field['data_type'],
        ];
      }
    }

    return $fields;
  }

  /**
   * @return array<string, mixed>
   */
  private function getWalletDefaults(): array {
    $defaults = [];
    if (!$this->getEventID()) {
      return $defaults;
    }

    $select = [];
    foreach ($this->walletFields as $field) {
      $select[] = 'custom_' . $field['id'];
    }

    if ($select === []) {
      return $defaults;
    }

    $event = \Civi\Api4\Event::get(FALSE)
      ->addSelect(...$select)
      ->addWhere('id', '=', $this->getEventID())
      ->execute()
      ->first();

    if (!$event) {
      return $defaults;
    }

    foreach ($this->walletFields as $field) {
      $elementName = 'custom_' . $field['id'];
      if (array_key_exists($elementName, $event) && $event[$elementName] !== NULL && $field['html_type'] !== 'File') {
        $defaults[$elementName] = $event[$elementName];
      }
    }

    return $defaults;
  }

  /**
   * @return array{fileName:string,fileId:int}|null
   */
  private function getCurrentImageInfo(): ?array {
    if (empty($this->walletFields['image']) || !$this->getEventID()) {
      return NULL;
    }

    $fieldId = $this->walletFields['image']['id'];
    $event = \Civi\Api4\Event::get(FALSE)
      ->addSelect('custom_' . $fieldId)
      ->addWhere('id', '=', $this->getEventID())
      ->execute()
      ->first();

    $fileId = (int) ($event['custom_' . $fieldId] ?? 0);
    if ($fileId <= 0) {
      return NULL;
    }

    $file = \Civi\Api4\File::get(FALSE)
      ->addSelect('uri')
      ->addWhere('id', '=', $fileId)
      ->execute()
      ->first();

    if (!$file) {
      return NULL;
    }

    return [
      'fileId' => $fileId,
      'fileName' => basename((string) $file['uri']),
    ];
  }

}
