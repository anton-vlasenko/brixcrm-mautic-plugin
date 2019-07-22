<?php

namespace MauticPlugin\MauticBrixCRMBundle\Integration;

use Mautic\LeadBundle\Entity\Lead;
use Mautic\PluginBundle\Entity\IntegrationEntity;
use MauticPlugin\MauticCrmBundle\Integration\CrmAbstractIntegration;
use Mautic\PluginBundle\Entity\Integration;

class BrixCRMIntegration extends CrmAbstractIntegration {

	public function getName() {
		return 'BrixCRM';
	}

	public function getSupportedFeatures() {
		return ['push_lead'];
	}

	public function getApiHelper() {
		static $helper;
		if (empty($helper)) {
			$class = '\\MauticPlugin\\MauticBrixCRMBundle\\Api\\' . $this->getName() . 'Api';
			$helper = new $class($this);
		}

		return $helper;
	}

	public function getRequiredKeyFields() {
		return [
			'sugarcrm_url' => 'mautic.sugarcrm.form.url',
			'username' => 'mautic.sugarcrm.form.username',
			'password' => 'mautic.sugarcrm.form.password',
		];
	}

	public function getSecretKeys() {
		return [
			'password',
		];
	}

	public function getAuthenticationType() {
		return 'oauth2';
	}

	public function setIntegrationSettings(Integration $settings) {
		parent::setIntegrationSettings($settings);
		$this->keys['client_id'] = 'sugar';
		$this->keys['client_secret'] = '';
	}

	public function prepareRequest($url, $parameters, $method, $settings, $authType)
	{
		if ($authType == 'oauth2' && empty($settings['authorize_session']) && isset($this->keys['access_token'])) {
			// Append the access token as the oauth-token header
			$headers = [
				"oauth-token: {$this->keys['access_token']}",
			];

			return [$parameters, $headers];
		} else {
			return parent::prepareRequest($url, $parameters, $method, $settings, $authType);
		}
	}

	public function getRefreshTokenKeys()
	{
		return [
			'refresh_token',
			'expires',
		];
	}

	public function getAccessTokenUrl() {
		return sprintf('%s/%s', $this->keys['sugarcrm_url'], 'rest/v10/oauth2/token');
	}

	public function getAuthLoginUrl() {
		return $this->router->generate('mautic_integration_auth_callback', ['integration' => $this->getName()]);
	}

	public function authCallback($settings = [], $parameters = []) {
		$settings = [
			'grant_type' => 'password',
			'ignore_redirecturi' => true,
		];
		$parameters = [
			'username' => $this->keys['username'],
			'password' => $this->keys['password'],
			'platform' => 'base',
		];

		return parent::authCallback($settings, $parameters);
	}

	public function __getFormLeadFields($settings = []) {
		return [];
	}

	public function getFormCompanyFields($settings = []) {
		return [];
	}

	public function cleanUpFields(Integration $entity, array $mauticLeadFields, array $mauticCompanyFields) {
		$featureSettings = $entity->getFeatureSettings();
		$entity->setFeatureSettings($featureSettings);

		return [];
	}

	public function pushLead($lead, $config = []) {
		try {
			if ($this->isAuthorized()) {
				$this->getApiHelper()->addToSugarQueue($lead, 'push');
				$this->updateIntegrationEntity($lead);

				return true;
			} else {
				throw new \Exception('BrixCRMIntegration: Not authorized');
			}
		} catch (\Exception $e) {
			$this->logIntegrationError($e);
		}

		return false;
	}

	public function updateIntegrationEntity(Lead $lead) {
		$integrationEntityRepo = $this->em->getRepository('MauticPluginBundle:IntegrationEntity');
		$integrationId = $integrationEntityRepo->getIntegrationsEntityId($this->getName(), $this->getIntegrationObject(), 'lead', $lead->getId());
		if (!empty($integrationId)) {
			$integrationEntity = $integrationEntityRepo->getEntity($integrationId[0]['id']);
		} else {
			$integrationEntity = new IntegrationEntity();
			$integrationEntity->setDateAdded(new \DateTime());
			$integrationEntity->setIntegration($this->getName());
			$integrationEntity->setIntegrationEntity($this->getIntegrationObject());
			$integrationEntity->setIntegrationEntityId('-');
			$integrationEntity->setInternalEntity('lead');
			$integrationEntity->setInternalEntityId($lead->getId());
		}
		$integrationEntity->setLastSyncDate(new \DateTime());

		$this->em->persist($integrationEntity);
		$this->em->flush($integrationEntity);
	}

	public function getIntegrationObject() {
		return 'Lead/Contact';
	}

	public function mergeApiKeys($mergeKeys, $withKeys = [], $return = false){
		if(array_key_exists('expires_in', $mergeKeys)){
			$mergeKeys['expires_in'] = time()+$mergeKeys['expires_in'];
		}

		if(array_key_exists('refresh_expires_in', $mergeKeys)){
			$mergeKeys['refresh_expires_in'] = time()+$mergeKeys['refresh_expires_in'];
		}

		return parent::mergeApiKeys($mergeKeys, $withKeys, $return);
	}
}
