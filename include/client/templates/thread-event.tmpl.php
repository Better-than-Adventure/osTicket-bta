<?php
$desc = $event->getDescription(ThreadEvent::MODE_CLIENT); 
if (!$desc)
    return;
?>
<div class="thread-event d-flex <?php if ($event->uid) echo 'action'; ?>">
        <span class="type-icon">
          <i class="faded icon icon-<?php echo $event->getIcon(); ?>"></i>
        </span>
        <span class="faded description"><?php echo $desc; ?></span>
</div>
