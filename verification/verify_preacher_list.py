from playwright.sync_api import Page, expect, sync_playwright
import time

def test_verify_preacher_list(page: Page):
    # 1. Login as admin
    print("Navigating to login page...")
    page.goto("http://127.0.0.1:8000/login")

    # Refresh to ensure session/CSRF is fresh
    page.reload()

    page.fill('input#email', 'admin@crockenhill.org')
    page.fill('input#password', 'password')
    print("Clicking login button...")
    page.click('button:has-text("Login")')

    # Wait for navigation after login
    try:
        page.wait_for_selector('text=Members', timeout=15000)
        print("Login successful, reached Members page.")
    except Exception as e:
        print(f"Failed to reach Members page or timeout: {e}")
        # Try to navigate directly if login might have worked but redirect failed
        page.goto("http://127.0.0.1:8000/admin/preachers")

    # 2. Go to Preachers admin page
    print("Navigating to Preachers admin page...")
    page.goto("http://127.0.0.1:8000/admin/preachers")

    # Wait for the table or login if redirected back
    if "login" in page.url:
        print("Redirected back to login, login failed.")
        page.screenshot(path="verification/login_failed_final.png")
        return

    page.wait_for_selector('table', timeout=15000)

    # 3. Check if the copy button exists in the actions column
    copy_button = page.locator('button[title="Copy profile link"]').first
    expect(copy_button).to_be_visible()
    print("Found copy profile button.")

    # 4. Take a screenshot
    page.screenshot(path="verification/preacher_list_with_copy_button.png", full_page=True)
    print("Screenshot saved to verification/preacher_list_with_copy_button.png")

if __name__ == "__main__":
    with sync_playwright() as p:
        browser = p.chromium.launch(headless=True)
        context = browser.new_context()
        page = context.new_page()
        try:
            test_verify_preacher_list(page)
        finally:
            browser.close()
