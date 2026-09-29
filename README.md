# 🎓 Scholarship Management Portal

A web-based Scholarship Management Portal developed using **PHP, MySQL, HTML, CSS, and XAMPP**.

The system provides a centralized platform to manage students, scholarships, applications, eligibility, and scholarship payments.

---

## 📌 Project Overview

Managing scholarship information manually can become difficult when student details, scholarship records, applications, and payment information are stored separately.

The Scholarship Management Portal provides a structured database-driven solution where these records can be stored, managed, and retrieved efficiently.

---

## ✨ Features

### 👨‍🎓 Student Management
- Add student records
- View student details
- Update student information
- Delete student records
- Store course and contact information

### 🎓 Scholarship Management
- Add scholarships
- View scholarship details
- Manage scholarship amount
- Store eligibility criteria
- Manage scholarship deadlines

### 📝 Application Management
- Submit scholarship applications
- Link students with scholarships
- Track application dates
- Track application status

### 💰 Payment Management
- Record scholarship payments
- Link payments with applications
- Track payment amount
- Track payment date
- Track payment status

### ✅ Eligibility Management
- Check student eligibility for scholarships
- Apply scholarship-specific eligibility conditions
- Maintain eligibility-related information

---

## 🛠️ Technologies Used

| Technology | Purpose |
|---|---|
| PHP | Backend development |
| MySQL | Database management |
| HTML | Page structure |
| CSS | User interface styling |
| XAMPP | Local development server |
| Git & GitHub | Version control |

---

## 🗄️ Database

The project uses **MySQL** as its relational database.

Main entities include:

- `Student`
- `Scholarship`
- `Application`
- `Payment`
- `Admin`

The database uses relationships between these entities to maintain scholarship application and payment information.

---

## 🔗 Main Relationships

```text
Student
   │
   │ applies for
   ▼
Application
   │
   │ belongs to
   ▼
Scholarship

Application
   │
   │ has
   ▼
Payment
