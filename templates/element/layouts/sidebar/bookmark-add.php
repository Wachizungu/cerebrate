<?php
echo $this->Bootstrap->button([
    'nodeType' => 'a',
    'icon' => 'plus',
    'title' => __('Add new bookmark'),
    'variant' => 'primary',
    'size' => 'sm',
    'class' => 'mb-1',
    'id' => 'btn-add-bookmark',
]);
