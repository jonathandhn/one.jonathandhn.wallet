<?php

interface CRM_Wallet_PassType_Interface
{
    /**
     * Create or update the Apple Wallet Pass
     *
     * @param int $entityId The ID of the entity (Participant, Contact, etc.)
     * @return void
     */
    public static function createApplePass($entityId);

    /**
     * Create or update the Google Wallet Pass
     *
     * @param int $entityId The ID of the entity
     * @return string JWT Link
     */
    public static function createGooglePass($entityId);
}
