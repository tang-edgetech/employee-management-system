<?php

it('reports the application as up', function () {
    $this->get('/up')->assertOk();
});
