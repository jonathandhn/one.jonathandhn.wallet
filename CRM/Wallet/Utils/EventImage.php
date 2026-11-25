<?php

class CRM_Wallet_Utils_EventImage {

  public static function publishForGoogle(int $eventId, string $sourcePath): string {
    return self::generateVariants($eventId, $sourcePath)['googleHeroUrl'];
  }

  /**
   * @return array{apple:array<string,string>,googleHeroUrl:string}
   */
  public static function generateVariants(int $eventId, string $sourcePath): array {
    return CRM_Wallet_Utils_ImageVariants::generateEventAssets($eventId, $sourcePath);
  }

}
