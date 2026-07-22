# CLAUDE.md

## Project Name

**SalonHub** (Working Name)

> A multi-tenant SaaS platform that enables salons and beauty parlors to create their own online booking website and manage appointments, staff, services, and customers.

---

# Vision

SalonHub helps small and medium salons create a professional online presence in minutes.

Instead of managing appointments through Facebook Messenger or WhatsApp, salons receive a beautiful booking website and an easy-to-use dashboard.

The first release focuses on solving **one problem extremely well**:

> **Allow customers to book appointments online while giving salon owners a simple management portal.**

Everything else can be added later.

---

# Core Principles

* Simplicity over features
* Mobile-first dashboard
* Multi-tenant architecture
* Fast booking experience
* Easy onboarding
* Single shared database
* Affordable hosting
* Easy scaling later

---

# Tech Stack

Backend

* Laravel 12
* PHP 8.4+
* MySQL
* Redis (Queues & Cache later)
* Laravel Queue
* Laravel Scheduler

Frontend

* Vue 3
* Pinia
* Vue Router
* Axios
* TailwindCSS

Infrastructure

* Nginx
* Cloudflare
* DigitalOcean VPS
* Supervisor
* Horizon (later)

---

# SaaS Model

```
Main Website

salonhub.com

        │

Register

        │

Organization Created

        │

Subdomain Generated

        │

beautyqueen.salonhub.com

        │

Dashboard

        │

Customers Visit

        │

Book Appointment
```

---

# Tenant Model

Single Database

Shared Tables

Every table contains

```
organization_id
```

No separate databases.

No database per tenant.

---

# Plans

## Free

* 1 Branch
* 10 Staff
* Unlimited Customers
* Unlimited Services
* Booking Website
* Calendar
* Basic Reports

---

## Starter (Later)

* 3 Branches
* 25 Staff

---

## Business (Later)

Unlimited everything

---

# MVP Features

## Authentication

* Register
* Login
* Forgot Password
* Email Verification
* Logout

---

## Organization

Create organization during registration

Fields

* Salon Name
* Slug
* Email
* Phone
* Country
* Timezone
* Currency

Automatically create

```
slug.salonhub.com
```

---

## Dashboard

Show

Today's

* Bookings
* Revenue (Later)
* Upcoming Appointments
* Staff
* Customers

---

## Branch Management

Free plan

Only one branch.

Fields

* Name
* Address
* Phone
* Google Map Link
* Opening Hours

---

## Staff

Fields

* Name
* Email
* Phone
* Position
* Profile Image
* Working Days
* Working Hours

Staff can perform multiple services.

---

## Services

Fields

* Name
* Category
* Duration
* Price
* Description
* Active

Example

```
Hair Cut

30 min

$12
```

---

## Customers

Fields

* Name
* Email
* Phone
* Notes

Created automatically after first booking.

---

## Appointment

Flow

Customer

↓

Choose Service

↓

Choose Staff

↓

Choose Date

↓

Choose Time

↓

Enter Information

↓

Book

↓

Confirmation

Status

* Pending
* Confirmed
* Completed
* Cancelled
* No Show

---

## Calendar

Month

Week

Day

View appointments

Drag & Drop later

---

## Public Website

Each organization receives

```
beautyqueen.salonhub.com
```

Contains

* Hero
* About
* Services
* Team
* Gallery
* Contact
* Google Map
* Book Appointment

---

## Settings

Organization

* Logo
* Banner
* Theme Color
* Phone
* Email
* Social Links

---

# Phase 2 (Not MVP)

* Payments
* SMS
* WhatsApp
* POS
* Inventory
* Payroll
* Membership
* Coupons
* Loyalty
* Analytics
* AI

---

# Roles

## Owner

Everything

---

## Manager

Appointments

Customers

Staff

Services

---

## Staff

View own schedule

Update appointment status

---

# Database Schema

---

## organizations

```sql
id
uuid
name
slug
email
phone
country
timezone
currency
logo
cover_image
subscription_plan
status
created_at
updated_at
```

---

## domains

```sql
id
organization_id
domain
is_primary
is_verified
ssl_enabled
created_at
updated_at
```

Supports

```
beautyqueen.salonhub.com

AND

beautyqueen.com
```

---

## branches

```sql
id
organization_id
name
phone
email
address
city
country
latitude
longitude
opening_hours_json
created_at
updated_at
```

---

## users

```sql
id
organization_id
branch_id
name
email
password
role
status
created_at
updated_at
```

Roles

```
owner

manager

staff
```

---

## staff_profiles

```sql
id
user_id
designation
bio
profile_image
working_days_json
working_hours_json
created_at
updated_at
```

---

## service_categories

```sql
id
organization_id
name
created_at
updated_at
```

---

## services

```sql
id
organization_id
category_id
name
description
duration
price
status
created_at
updated_at
```

---

## staff_services

Pivot

```sql
staff_id
service_id
```

---

## customers

```sql
id
organization_id
name
phone
email
notes
created_at
updated_at
```

---

## appointments

```sql
id
organization_id
branch_id
customer_id
staff_id
service_id

booking_date
start_time
end_time

status

notes

created_at
updated_at
```

Status

```
Pending

Confirmed

Completed

Cancelled

No Show
```

---

## galleries

```sql
id
organization_id
image
title
sort_order
created_at
updated_at
```

---

## business_hours

```sql
id
branch_id
weekday
open_time
close_time
is_closed
```

---

## settings

```sql
id
organization_id
theme_color
about
facebook
instagram
website
created_at
updated_at
```

---

# Folder Structure

```
app/

    Actions/

    Services/

    Repositories/

    Models/

    Policies/

    Jobs/

    Events/

    Listeners/

    Notifications/

    DTO/

    Enums/

    Traits/

    Helpers/
```

Controllers remain thin.

Business logic belongs inside Services.

---

# API Structure

```
/api

/auth

/dashboard

/branches

/staff

/services

/categories

/customers

/appointments

/settings

/gallery
```

RESTful.

---

# Booking Flow

```
Customer

↓

Landing Page

↓

Select Service

↓

Select Staff

↓

Select Date

↓

Available Times

↓

Enter Details

↓

Confirm Booking

↓

Appointment Created

↓

Salon Dashboard Updated
```

---

# Tenant Resolution

Every request

↓

Read Host Header

Example

```
beautyqueen.salonhub.com
```

↓

Find organization

↓

Set Current Tenant

↓

Every query automatically filters by

```
organization_id
```

Never expose another tenant's data.

---

# Validation Rules

Free Plan

```
Maximum

1 Branch

10 Staff
```

Prevent creation when limits are exceeded.

---

# Security

* Authorization Policies
* Form Requests
* CSRF Protection
* Rate Limiting
* Password Hashing
* Email Verification
* Audit Logs (Later)

---

# Performance

Cache

* Settings
* Services
* Public Website

Queue

* Emails
* Notifications

Indexes

```
organization_id

branch_id

booking_date

staff_id

status
```

---

# Coding Standards

* SOLID Principles
* Repository Pattern where appropriate
* Service Layer
* DTOs for complex operations
* Action Classes for reusable business logic
* Enums instead of magic strings
* Form Request Validation
* API Resources
* Feature Tests
* No business logic inside controllers

---

# Future Roadmap

Version 1.1

* Online Payments
* Email Confirmation
* Appointment Reminder

Version 1.2

* Custom Domains
* Reviews
* Staff Leave Management

Version 2.0

* POS
* Inventory
* Loyalty Program
* Membership
* Gift Cards

Version 3.0

* AI Assistant
* Marketing Automation
* WhatsApp Integration
* SMS
* Reports
* Multi-location Analytics

---

# Success Criteria for MVP

A successful initial release is achieved when a salon owner can:

1. Register in under 2 minutes.
2. Receive a branded subdomain automatically.
3. Create one branch.
4. Add staff members (up to the free plan limit).
5. Configure services and prices.
6. Share their public booking website with customers.
7. Receive and manage appointments through a clean dashboard.
8. Deliver a smooth booking experience on both desktop and mobile.

**Important Principle:** Every feature added to the MVP should answer the question, *"Does this directly help a salon owner accept and manage appointments?"* If the answer is **no**, it belongs in a later release. This discipline keeps development focused, reduces costs, and increases the likelihood of reaching paying customers quickly.
