import os
import time
from playwright.sync_api import sync_playwright

def verify_church_services_hub(page):
    # 1. Login as admin
    print("Navigating to login page...")
    page.goto("http://localhost:8000/login")

    print("Filling credentials...")
    page.fill("#email", "admin@crockenhill.org")
    page.fill("#password", "password")

    print("Clicking Log in...")
    page.click("button:has-text('Log in')")

    # Wait for session to be established
    time.sleep(3)
    print(f"Current URL after login: {page.url}")

    # 2. Navigate to Church Services Hub
    print("Navigating to admin services...")
    page.goto("http://localhost:8000/admin/services")

    # Wait for the content to render
    time.sleep(5)
    print(f"Current URL at services: {page.url}")

    os.makedirs("verification", exist_ok=True)
    page.screenshot(path="verification/church_services_hub.png", full_page=True)
    print("Screenshot saved to verification/church_services_hub.png")

    # Also save the HTML for inspection if needed
    with open("verification/church_services_hub.html", "w") as f:
        f.write(page.content())

if __name__ == "__main__":
    with sync_playwright() as p:
        browser = p.chromium.launch(headless=True)
        context = browser.new_context(viewport={'width': 1280, 'height': 1024})
        page = context.new_page()
        try:
            verify_church_services_hub(page)
        except Exception as e:
            print(f"Error during verification: {e}")
            try:
                page.screenshot(path="verification/error.png")
            except:
                pass
        finally:
            browser.close()
