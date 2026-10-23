<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminPanelTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_admin_panel_redirects_guests_to_the_login_page(): void
    {
        $this->get('/admin')->assertRedirect('/admin/login');

        $this->get('/admin/login')->assertOk();
    }

    public function test_authorized_users_can_open_the_pdf_documents_page(): void
    {
        $user = User::factory()->create(['admin' => true]);

        $this->actingAs($user)
            ->get('/admin/pdf-documents')
            ->assertOk()
            ->assertSee('PDF Documents');
    }

    public function test_non_admin_users_are_forbidden(): void
    {
        $user = User::factory()->create(['admin' => false]);

        $this->actingAs($user)
            ->get('/admin')
            ->assertForbidden();
    }
}
