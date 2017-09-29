<?php

namespace EMS\CoreBundle\DependencyInjection;

use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;

/**
 * This is the class that validates and merges configuration from your app/config files.
 *
 * To learn more see {@link http://symfony.com/doc/current/cookbook/bundles/configuration.html}
 */
class Configuration implements ConfigurationInterface
{
	const PAGING_SIZE = 20;
	const SHORTNAME = 'e<b>ms</b>';
	const NAME = 'elastic<b>ms</b>';
	const THEME_COLOR = 'blue';
	const DATE_TIME_FORMAT = 'j/m/Y \a\t G:i';
	const FROM_EMAIL_ADDRESS = 'noreply@example.com';
	const FROM_EMAIL_NAME = 'elasticms';
	const INSTANCE_ID = 'ems_';
	const CIRCLES_OBJECT = null;
	const ELASTICSEARCH_DEFAULT_SERVER = 'http://localhost:9200';
	const DATEPICKER_FORMAT = 'dd/mm/yyyy';
	const DATEPICKER_WEEKSTART = 1;
	const DATEPICKER_DAYSOFWEEK_HIGHLIGHTED = [0,6];
	const AUDIT_INDEX = null;
	const NOTIFICATION_PENDING_TIMEOUT = 'P0Y0M15DT0H0M0S';
	const ALLOW_USER_REGISTRATION = false;
	const LOCK_TIME = '+1 minutes';
	const USER_LOGIN_ROUTE = 'fos_user_security_login';
	const USER_PROFILE_ROUTE = 'fos_user_profile_show';
	const USER_LOGOUT_ROUTE = 'fos_user_security_logout';
	const USER_REGISTRATION_ROUTE = 'fos_user_registration_register';
	const ADD_USER_ROUTE = 'user.add';
	const APPLICATION_MENU_CONTROLLER = null;
	const PRIVATE_KEY = null;
	const ASSET_CONFIG_TYPE = null;
	const ASSET_CONFIG_INDEX = null;
	
	
    /**
     * {@inheritdoc}
     */
    public function getConfigTreeBuilder()
    {
        $treeBuilder = new TreeBuilder();
        $rootNode = $treeBuilder->root('ems_core');

        $rootNode->addDefaultsIfNotSet()->children()
		        ->scalarNode('paging_size')->defaultValue(self::PAGING_SIZE)->end()
		        ->scalarNode('circles_object')->defaultValue(self::CIRCLES_OBJECT)->end()
		        ->scalarNode('shortname')->defaultValue(self::SHORTNAME)->end()
		        ->scalarNode('name')->defaultValue(self::NAME)->end()
		        ->scalarNode('theme_color')->defaultValue(self::THEME_COLOR)->end()
		        ->scalarNode('date_time_format')->defaultValue(self::DATE_TIME_FORMAT)->end()
		        ->scalarNode('instance_id')->defaultValue(self::INSTANCE_ID)->end()
		        ->scalarNode('datepicker_format')->defaultValue(self::DATEPICKER_FORMAT)->end()
		        ->scalarNode('datepicker_weekstart')->defaultValue(self::DATEPICKER_WEEKSTART)->end()
		        ->arrayNode('elasticsearch_cluster')->requiresAtLeastOneElement()->defaultValue([self::ELASTICSEARCH_DEFAULT_SERVER])
		       		->prototype('scalar')->end()
		       	->end()
		        ->arrayNode('datepicker_daysofweek_highlighted')->requiresAtLeastOneElement()->defaultValue([self::DATEPICKER_DAYSOFWEEK_HIGHLIGHTED])
		       		->prototype('scalar')->end()
		       	->end()
		        ->arrayNode('from_email')->addDefaultsIfNotSet()
		        	->children()
			        	->scalarNode('address')->defaultValue(self::FROM_EMAIL_ADDRESS)->end()
			        	->scalarNode('sender_name')->defaultValue(self::FROM_EMAIL_NAME)->end()
			        ->end()
		        ->end()
		        ->scalarNode('audit_index')->defaultValue(self::AUDIT_INDEX)->end()
		        ->scalarNode('date_time_format')->defaultValue(self::DATE_TIME_FORMAT)->end()
		        ->scalarNode('notification_pending_timeout')->defaultValue(self::NOTIFICATION_PENDING_TIMEOUT)->end()
		        ->scalarNode('allow_user_registration')->defaultValue(self::ALLOW_USER_REGISTRATION)->end()
		        ->scalarNode('lock_time')->defaultValue(self::LOCK_TIME)->end()
		        ->scalarNode('user_login_route')->defaultValue(self::USER_LOGIN_ROUTE)->end()
		        ->scalarNode('user_profile_route')->defaultValue(self::USER_PROFILE_ROUTE)->end()
		        ->scalarNode('user_logout_route')->defaultValue(self::USER_LOGOUT_ROUTE)->end()
		        ->scalarNode('user_registration_route')->defaultValue(self::USER_REGISTRATION_ROUTE)->end()
		        ->scalarNode('add_user_route')->defaultValue(self::ADD_USER_ROUTE)->end()
		        ->scalarNode('application_menu_controller')->defaultValue(self::APPLICATION_MENU_CONTROLLER)->end()
		        ->scalarNode('asset_config_type')->defaultValue(self::ASSET_CONFIG_TYPE)->end()
		        ->scalarNode('asset_config_index')->defaultValue(self::ASSET_CONFIG_INDEX)->end()
		        ->scalarNode('private_key')->defaultValue(self::PRIVATE_KEY)->end()
		        ->arrayNode('template_options')->defaultValue([])
		        	->prototype('variable')
		        	->end()
		        ->end()
	        ->end();

        return $treeBuilder;
    }
}
