<?php

it('responds on the example package route', function () {
    $this->get('/backup')
        ->assertOk()
        ->assertJson(['package' => 'backup'])
        ->assertJsonStructure(['package', 'version']);
});
