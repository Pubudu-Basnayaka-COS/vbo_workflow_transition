<?php

namespace Drupal\vbo_workflow_transition\Plugin\Action;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\content_moderation\ModerationInformationInterface;
use Drupal\content_moderation\StateTransitionValidationInterface;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Entity\EditorialContentEntityBase;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Render\Markup;
use Drupal\Core\Session\AccountInterface;
use Drupal\views_bulk_operations\Action\ViewsBulkOperationsActionBase;
use Drupal\workflows\WorkflowInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Change the moderation state of entities.
 *
 * @Action(
 *   id = "vbo_workflow_transition_",
 *   label = @Translation("Transition content to a new workflow state"),
 *   type = ""
 * )
 */
class VboWorkflowTransition extends ViewsBulkOperationsActionBase implements ContainerFactoryPluginInterface {

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * The moderation info service.
   *
   * @var \Drupal\content_moderation\ModerationInformationInterface
   */
  protected $moderationInfo;

  /**
   * The current user.
   *
   * @var \Drupal\Core\Session\AccountInterface
   */
  protected $currentUser;

  /**
   * The time interface.
   *
   * @var \Drupal\Component\Datetime\TimeInterface
   */
  protected TimeInterface $dateTime;

  /**
   * The state transition validation service.
   *
   * @var \Drupal\content_moderation\StateTransitionValidationInterface
   */
  protected $validator;

  /**
   * ModerateEntities constructor.
   *
   * @param array $configuration
   *   A configuration array containing information about the plugin instance.
   * @param string $plugin_id
   *   The plugin_id for the plugin instance.
   * @param mixed $plugin_definition
   *   The plugin implementation definition.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   * @param \Drupal\content_moderation\ModerationInformationInterface $moderation_info
   *   The moderation information service.
   * @param \Drupal\Core\Messenger\MessengerInterface $messenger
   *   Messenger service.
   * @param \Drupal\Core\Session\AccountInterface $current_user
   *   Current user.
   * @param \Drupal\content_moderation\StateTransitionValidationInterface $validator
   *   The state transition validation service.
   * @param \Drupal\Component\Datetime\TimeInterface $dateTime
   *   The time interface.
   */
  public function __construct(array $configuration, $plugin_id, $plugin_definition, EntityTypeManagerInterface $entity_type_manager, ModerationInformationInterface $moderation_info, MessengerInterface $messenger, AccountInterface $current_user, StateTransitionValidationInterface $validator, TimeInterface $dateTime) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->entityTypeManager = $entity_type_manager;
    $this->moderationInfo = $moderation_info;
    $this->messenger = $messenger;
    $this->currentUser = $current_user;
    $this->validator = $validator;
    $this->dateTime = $dateTime;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration, $plugin_id, $plugin_definition,
      $container->get('entity_type.manager'),
      $container->get('content_moderation.moderation_information'),
      $container->get('messenger'),
      $container->get('current_user'),
      $container->get('content_moderation.state_transition_validation'),
      $container->get('datetime.time')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function execute(EditorialContentEntityBase $entity = NULL): void {
    // Since ALL entities are still passed to execute, they need to be checked
    // that they can handle the given workflow and transition.
    $workflow = $this->getWorkFlowAndLatestRev($entity, FALSE);
    if (NULL === $workflow) {
      return;
    }
    // Determine the new moderation state that applies to this transition.
    $new_state = NULL;
    $transition_entities = $this->validator->getValidTransitions($entity, $this->currentUser);
    foreach ($transition_entities as $transition_entity) {
      if ($transition_entity->id() === $this->configuration['transition_id']) {
        $new_state = $transition_entity->to();
        break;
      }
    }
    if (NULL === $new_state) {
      return;
    }

    // Set a new moderation state.
    $entity->set('moderation_state', $new_state->id());

    // Always make sure a new revision is created.
    $entity->setNewRevision(TRUE);
    // Optional, set a log message for this revision.
    $entity->setRevisionLogMessage($this->configuration['revision_log_message']);
    // Optional, set a new time for the revision log message. This will
    // default to the last revision log time.
    $entity->setRevisionCreationTime($this->dateTime->getRequestTime());
    // Optional, set a new time for the updated time. This will default
    // to the last revision changed time.
    $entity->setChangedTime($this->dateTime->getRequestTime());
    // This is REQUIRED and I'm not sure why. If this flag is not
    // set, the revision will not show in the revisions tab.
    $entity->setRevisionTranslationAffected(TRUE);
    // Ensure the current user is the revision user.
    $entity->setRevisionUserId($this->currentUser->id());

    $entity->save();
  }

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration(): array {
    return [
      'transition_id' => '',
      'workflow_id' => '',
      'revision_log_message' => '',
    ];
  }

  /**
   * Display an error message and return the form array.
   *
   * @param array $form
   *   The current form.
   * @param string $message
   *   An error message to display.
   *
   * @return array
   *   The form array.
   */
  protected function unsupported(array $form, string $message = 'The view does not support passing entities correctly.'): array {
    $this->messenger->addError($message);
    return $form;
  }

  /**
   * Retrieve the workflow for the given entity.
   *
   * This will also overwrite the $entity with the latest revision if it is not
   * the $entity.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   An entity object.
   * @param bool $showErrors
   *   If true, will add messenger warnings.
   *
   * @return \Drupal\workflows\WorkflowInterface|null
   *   Returns the workflow for the entity or null if unable.
   */
  protected function getWorkFlowAndLatestRev(EntityInterface &$entity, bool $showErrors = TRUE): ?WorkflowInterface {
    if (FALSE === $entity instanceof EditorialContentEntityBase) {
      if ($showErrors) {
        $this->messenger->addWarning($this->t('"@entity" is not an editorial entity, it cannot change state.', ['@entity' => $entity->label()]));
      }
      return NULL;
    }
    $workflow = $this->moderationInfo->getWorkflowForEntity($entity);
    if (NULL === $workflow) {
      if ($showErrors) {
        $this->messenger->addWarning($this->t('"@entity" is not a moderated entity, it cannot change state.', ['@entity' => $entity->label()]));
      }
      return NULL;
    }
    // We only want to work with the latest revision.
    if (FALSE === $entity->isLatestRevision()) {
      $node_storage = $this->entityTypeManager->getStorage('node');
      $entity = $node_storage->loadRevision($node_storage->getLatestRevisionId($entity->id()));
    }
    return $workflow;
  }

  /**
   * Retrieve the moderation state label.
   *
   * @param \Drupal\workflows\WorkflowInterface $workflow
   *   A workflow entity.
   * @param string $moderation_state_id
   *   An ID of a moderation state.
   *
   * @return string
   *   The label for a moderation state.
   */
  protected function getWorkFlowStateLabel(WorkflowInterface $workflow, string $moderation_state_id): string {
    return $workflow->getTypePlugin()->getState($moderation_state_id)->label();
  }

  /**
   * Configuration form builder.
   *
   * If this method has implementation, the action is
   * considered to be configurable.
   *
   * @param array $form
   *   Form array.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state object.
   *
   * @return array
   *   The configuration form.
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state): array {
    // Remove the submit button, we'll use individual ones for each group.
    unset($form['actions']['submit']);
    $storage = $form_state->getStorage();
    if (empty($storage['views_bulk_operations']['list'])) {
      return $this->unsupported($form);
    }
    $total_entities = 0;
    $entity_ids_to_load = [];
    foreach ($storage['views_bulk_operations']['list'] as $entity_ids) {
      if (empty($entity_ids[2]) || empty($entity_ids[3])) {
        return $this->unsupported($form);
      }
      // Key: entity type Values: entity IDs.
      $entity_ids_to_load[$entity_ids[2]][] = $entity_ids[3];
      $total_entities++;
    }
    $transitions = [];
    foreach ($entity_ids_to_load as $entity_type => $entity_ids) {
      $entities = $this->entityTypeManager->getStorage($entity_type)->loadMultiple($entity_ids);
      foreach ($entities as $entity) {
        if (empty($entity->moderation_state)) {
          continue;
        }
        $current_moderation_state_id = $entity->moderation_state->value;
        $workflow = $this->getWorkFlowAndLatestRev($entity, TRUE);
        if (NULL === $workflow) {
          continue;
        }
        // Any entity that makes it this far, is a revisionable and workflow
        // enabled entity.
        /** @var \Drupal\Core\Entity\EditorialContentEntityBase $entity */
        $current_moderation_state_label = $this->getWorkFlowStateLabel($workflow, $current_moderation_state_id);
        $latest_moderation_state_label = $this->getWorkFlowStateLabel($workflow, $entity->get('moderation_state')->getString());
        // Determine what transitions this entity can make.
        $transition_entities = $this->validator->getValidTransitions($entity, $this->currentUser);
        /** @var \Drupal\workflows\Transition $transition */
        if (!empty($transition_entities)) {
          foreach ($transition_entities as $transition_id => $transition) {
            $transitions[$workflow->id()]['transitions'][$transition_id]['label'] = $transition->label();
            $transitions[$workflow->id()]['transitions'][$transition_id]['to'] = $transition->to()->label();
            $transitions[$workflow->id()]['label'] = $workflow->label();
            $extra = " (Current State: " . $current_moderation_state_label;
            // Make it clear the latest revision is different from the current.
            if ($current_moderation_state_label !== $latest_moderation_state_label) {
              $extra .= ", Latest State: " . $latest_moderation_state_label;
            }
            $extra .= ")";
            $transitions[$workflow->id()]['transitions'][$transition_id]['entities'][] = [
              'id' => $entity->id(),
              'label' => $entity->label() . $extra,
            ];
          }
        }
      }
      if (count($entity_ids) !== count($entities)) {
        return $this->unsupported($form, $this->t('All entities of type @type were unable to be loaded.', ['@type' => $entity_type]));
      }
    }
    // No need to show the rest of the form if there are no transitions.
    if (empty($transitions)) {
      return $this->unsupported($form, $this->t('None of the selected entities have any transitions available to them. Your account may not have permission to transitions that might be supported.'));
    }

    foreach ($transitions as $workflow_id => $workflow_info) {
      $form['revision_log_message'] = [
        '#type' => 'textarea',
        '#title' => $this
          ->t('Revision Log Message'),
        '#rows' => 4,
        '#maxlength' => 255,
        '#description' => $this->t('This message will display in the "Revisions" tab of each piece of content.'),
        '#attributes' => ['placeholder' => $this->t('Why are you making this transition?')],
      ];
      $form['next'] = [
        '#type' => 'markup',
        '#markup' => Markup::create('<h3>Choose a Transition to Make</h3><p>Only valid transitions that you can make will be available. Only one transition can be chosen.</p>'),
      ];
      $form['workflow'] = [
        '#type' => 'details',
        '#title' => $this->t('Workflow: @workflow', ['@workflow' => $workflow_info['label']]),
        '#description' => $this->t('One may only choose one transition at a time.'),
        '#open' => TRUE,
      ];
      foreach ($workflow_info['transitions'] as $transition_id => $transition_info) {
        $form['workflow'][$transition_id] = [
          '#type' => 'details',
          '#title' => $this->t('@transition: @trans_num of @total_num selected entities can make the transition to %to', [
            '@trans_num' => count($transition_info['entities']),
            '@total_num' => $total_entities,
            '@transition' => $transition_info['label'],
            '%to' => $transition_info['to'],
          ]),
          '#open' => FALSE,
        ];
        $entity_labels = [];
        foreach ($transition_info['entities'] as $entity) {
          $entity_labels[] = $entity['label'];
        }
        $form['workflow'][$transition_id]['list'] = [
          '#theme' => 'item_list',
          '#items' => $entity_labels,
        ];
        $form['workflow'][$transition_id]['submit'] = [
          '#type' => 'submit',
          '#value' => $transition_info['label'],
          // This will make a new duplicate form state value of 'submit:x'.
          // That will let us know which transition they want to make since
          // it will only be set for the clicked.
          '#name' => 'submit:' . $transition_id . ':' . $workflow_id,
        ];
      }
    }

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitConfigurationForm(array &$form, FormStateInterface $form_state): void {
    foreach ($form_state->getValues() as $key => $value) {
      // The key looks like: submit:TRANSITION_ID:WORKFLOW_ID.
      if (str_starts_with($key, 'submit:')) {
        $values = explode(':', $key);
        $this->configuration['transition_id'] = $values[1];
        $this->configuration['workflow_id'] = $values[2];
        $this->configuration['revision_log_message'] = $form_state->getValue('revision_log_message');
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  public function access($object, AccountInterface $account = NULL, $return_as_object = FALSE): bool|AccessResultInterface {
    // Since it is unknown in this method what transition they are going to make
    // the access check will be done in the execute method, skipping any items
    // they don't have access to set moderation on.
    /** @var \Drupal\Core\Entity\ContentEntityInterface $object */
    $access = $object->access('update', $account, TRUE);
    return $return_as_object ? $access : $access->isAllowed();
  }

}
