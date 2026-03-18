# SEO Setup Guide

This guide walks you through the manual setup steps needed to maximize the SEO improvements implemented in this project.

## Table of Contents

1. [Google Search Console Setup](#google-search-console-setup)
2. [Google Analytics 4 Setup](#google-analytics-4-setup)
3. [Google Business Profile Setup](#google-business-profile-setup)
4. [Validation Tools](#validation-tools)
5. [Ongoing Maintenance](#ongoing-maintenance)

---

## Google Search Console Setup

Google Search Console helps you monitor and maintain your site's presence in Google Search results.

### 1. Create an Account

1. Go to [Google Search Console](https://search.google.com/search-console)
2. Sign in with your Google account (or create one)
3. Click "Add Property"

### 2. Choose Property Type

- **Domain Property** (recommended): Verifies all URLs across all subdomains and protocols
  - Enter: `crockenhill.org`
  - Requires DNS verification (see below)

- **URL Prefix Property**: Verifies only specific URL patterns
  - Enter: `https://crockenhill.org`
  - Easier verification options (HTML file, HTML tag, etc.)

### 3. Verify Ownership

**For Domain Property (via DNS):**
1. Copy the TXT record provided by Google
2. Add it to your DNS settings (contact your hosting provider if needed)
3. Wait for DNS propagation (can take up to 48 hours, but usually faster)
4. Click "Verify" in Google Search Console

**For URL Prefix Property:**
Choose one of these methods:
- **HTML file**: Upload verification file to your website root
- **HTML tag**: Add meta tag to `<head>` section (in `layouts/main.blade.php`)
- **Google Analytics**: Use your existing GA tracking code
- **Google Tag Manager**: Use your GTM container

### 4. Submit Your Sitemap

1. In Google Search Console, go to "Sitemaps" in the left sidebar
2. Enter your sitemap URL: `https://crockenhill.org/sitemap.xml`
3. Click "Submit"

**What this does:**
- Helps Google discover all your pages faster
- Provides metadata about your content
- Shows you which URLs are indexed

### 5. Monitor Search Performance

After a few days, you'll see:
- **Performance Report**: Clicks, impressions, CTR, average position
- **Coverage Report**: Which pages are indexed and any errors
- **Enhancements**: Mobile usability, Core Web Vitals, structured data

---

## Google Analytics 4 Setup

Google Analytics 4 (GA4) provides insights into user behavior on your website.

### 1. Create a GA4 Property

1. Go to [Google Analytics](https://analytics.google.com)
2. Sign in with your Google account
3. Click "Admin" (gear icon at bottom left)
4. Under "Account", click "Create Account" (or use existing)
5. Under "Property", click "Create Property"

### 2. Configure Your Property

1. **Property name**: `Crockenhill Baptist Church`
2. **Reporting time zone**: `United Kingdom`
3. **Currency**: `Pound Sterling (£)`
4. Click "Next"

### 3. Business Information

1. **Industry category**: `Religion & Spirituality`
2. **Business size**: Select appropriate size
3. Click "Create"
4. Accept Terms of Service

### 4. Set Up Data Stream

1. Choose platform: **Web**
2. **Website URL**: `https://crockenhill.org`
3. **Stream name**: `Crockenhill Website`
4. Click "Create stream"

### 5. Get Your Measurement ID

1. After creating the stream, you'll see your **Measurement ID**
   - Format: `G-XXXXXXXXXX`
2. Copy this ID

### 6. Add to Your Website

1. Open your `.env` file
2. Add your Measurement ID:
   ```
   GOOGLE_ANALYTICS_ID=G-XXXXXXXXXX
   ```
3. Save the file
4. Restart your application if needed

**Note**: The GA4 tracking code is automatically loaded in `layouts/main.blade.php` when `GOOGLE_ANALYTICS_ID` is set.

### 7. Verify Installation

1. Visit your website
2. In GA4, go to "Reports" > "Realtime"
3. You should see your visit appear within a few seconds

---

## Google Business Profile Setup

Google Business Profile (formerly Google My Business) helps your church appear in Google Maps and local search results.

### 1. Create Your Profile

1. Go to [Google Business Profile](https://www.google.com/business/)
2. Sign in with your Google account
3. Click "Manage now"
4. Enter your business name: `Crockenhill Baptist Church`

### 2. Choose Business Category

1. **Primary category**: `Baptist Church`
2. **Additional categories** (optional):
   - `Church`
   - `Place of Worship`
   - `Religious Organization`

### 3. Add Your Location

1. **Do you want to add a location?**: Yes
2. **Address**:
   ```
   Eynsford Road
   Crockenhill
   Kent
   BR8 8JS
   United Kingdom
   ```

### 4. Add Contact Information

1. **Phone**: `01322 663995`
2. **Website**: `https://crockenhill.org`

### 5. Verify Your Business

Google will send a verification postcard to your address with a verification code. This can take 5-7 business days.

**Alternative verification methods** (if available):
- Phone verification
- Email verification
- Video verification

### 6. Complete Your Profile

After verification, add:

1. **Business hours**: Your service times
2. **Photos**: Church building, interior, services
3. **Description**: Use your homepage description
4. **Services**: List your activities (Sunday services, Bible study, etc.)
5. **Attributes**: Wheelchair accessible, etc.

### 7. Create Posts

Regularly post updates about:
- Upcoming events
- Sermon series
- Special services (Christmas, Easter)
- Community activities

---

## Validation Tools

Use these tools to verify your SEO implementation is working correctly.

### Schema.org Validation

**Tool**: [Google Rich Results Test](https://search.google.com/test/rich-results)

1. Enter your homepage URL: `https://crockenhill.org`
2. Click "Test URL"
3. Verify the Church schema appears with all details

**Look for**:
- Organization type: Church
- Address, phone, email
- GPS coordinates
- Social media links

### Meta Tags Validation

**Tool**: [Meta Tags Inspector](https://metatags.io/)

1. Enter any page URL
2. Verify:
   - Meta description is present (155 characters or less)
   - Title tag is formatted correctly
   - All required meta tags are present

### Open Graph Validation

**Tool**: [Facebook Sharing Debugger](https://developers.facebook.com/tools/debug/)

1. Enter any page URL
2. Click "Debug"
3. Verify:
   - Title appears correctly
   - Description is displayed
   - Image shows up (church logo)
   - All OG properties are present

**Refresh Facebook's cache** if you make changes:
1. Paste URL in debugger
2. Click "Scrape Again"

### Twitter Card Validation

**Tool**: [Twitter Card Validator](https://cards-dev.twitter.com/validator)

1. Enter any page URL
2. Verify:
   - Card type: summary_large_image
   - Title, description, and image display correctly

### Sitemap Validation

**Tool**: [XML Sitemap Validator](https://www.xml-sitemaps.com/validate-xml-sitemap.html)

1. Enter: `https://crockenhill.org/sitemap.xml`
2. Verify:
   - No errors
   - All important pages are included
   - URLs are correctly formatted

### Page Speed Insights

**Tool**: [Google PageSpeed Insights](https://pagespeed.web.dev/)

1. Enter any page URL
2. Check both mobile and desktop scores
3. Address any critical performance issues

---

## Ongoing Maintenance

### Monthly Tasks

**1. Review Search Console**
- Check for crawl errors
- Monitor search performance
- Review new indexed pages

**2. Update Google Business Profile**
- Post about upcoming events
- Add new photos
- Respond to any reviews

**3. Review Analytics**
- Check most visited pages
- Monitor user behavior
- Identify trends

### Quarterly Tasks

**1. Content Audit**
- Update outdated meta descriptions
- Refresh old content
- Add new pages for seasonal events

**2. Technical SEO Check**
- Verify sitemap is up to date
- Check for broken links
- Test mobile usability

**3. Competitor Analysis**
- Search for local churches
- Review their SEO strategies
- Identify improvement opportunities

### Annual Tasks

**1. Schema Markup Update**
- Verify Organization schema is accurate
- Add new schema types if applicable (Event schema for special services)

**2. Local SEO Audit**
- Update Google Business Profile hours
- Refresh photos
- Verify NAP (Name, Address, Phone) consistency across web

**3. Goals Review**
- Set new GA4 conversion goals
- Review SEO performance against objectives
- Plan next year's SEO strategy

---

## Troubleshooting

### Sitemap Not Appearing in Google

**Symptoms**: Google Search Console shows errors when submitting sitemap

**Solutions**:
1. Verify sitemap is accessible: Visit `https://crockenhill.org/sitemap.xml`
2. Check robots.txt allows sitemap: `https://crockenhill.org/robots.txt`
3. Wait 24-48 hours after submission
4. Resubmit if necessary

### Meta Descriptions Not Showing in Search

**Symptoms**: Google shows different text than your meta description

**Solutions**:
1. **This is normal**: Google may choose different text if it's more relevant to the search
2. Ensure meta descriptions are 120-155 characters
3. Make them compelling and relevant to page content
4. Include target keywords naturally

### Organization Schema Not Appearing

**Symptoms**: Rich Results Test doesn't show Church schema

**Solutions**:
1. Verify JSON-LD is in page source (View > Page Source)
2. Check for syntax errors in [Schema.org Organization Component](../resources/views/components/schema/organization.blade.php)
3. Validate JSON-LD separately: [JSON-LD Playground](https://json-ld.org/playground/)
4. Clear cache and test again

### Google Analytics Not Tracking

**Symptoms**: Realtime report shows no visitors

**Solutions**:
1. Verify `GOOGLE_ANALYTICS_ID` is set in `.env`
2. Check Measurement ID format: `G-XXXXXXXXXX`
3. Disable ad blockers when testing
4. Verify script is in page source
5. Wait 24-48 hours for data to populate

---

## Resources

- [Google Search Central](https://developers.google.com/search) - Official SEO documentation
- [Schema.org](https://schema.org) - Structured data documentation
- [Google Analytics Help](https://support.google.com/analytics) - GA4 documentation
- [Google Business Profile Help](https://support.google.com/business) - GBP documentation
- [Moz Beginner's Guide to SEO](https://moz.com/beginners-guide-to-seo) - Comprehensive SEO guide

---

## Need Help?

If you encounter issues not covered in this guide:

1. Check [Google Search Central Help Community](https://support.google.com/webmasters/community)
2. Review Laravel documentation for configuration issues
3. Test with validation tools listed above
4. Check browser console for JavaScript errors
5. Review server logs for errors

---

**Last Updated**: January 2026
