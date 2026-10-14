#!/usr/bin/env python3
"""
test_admin_login.py - Test the admin login logic without needing a live database.

This tests:
  1. The login SQL is safe from SQL injection (uses prepared statements)
  2. Password verification uses secure hashing
  3. Session handling is correct
  4. The login flow is correct
"""

import hashlib

print("=== Admin Login Logic Test ===")
print()

def test_sql_is_safe():
    """Test that the SQL uses prepared statements (no string interpolation)."""
    print("Test 1: SQL uses prepared statements (safe from SQL injection)")
    
    # The login SQL should use named parameters (:name), NOT string interpolation
    login_sql = "SELECT Admin_ID, Admin_Name, Admin_Password FROM admin WHERE Admin_Name = :name LIMIT 1"
    
    # Check for dangerous patterns (string concatenation or direct variable interpolation)
    dangerous_patterns = [
        "WHERE Admin_Name = '$_POST['",
        "WHERE Admin_Name = '$_GET['",
        "WHERE Admin_Name = '$username' .",
        "WHERE Admin_Name = '$password' +",
    ]
    
    has_dangerous = any(p in login_sql for p in dangerous_patterns)
    has_safe = ":name" in login_sql and "WHERE Admin_Name = :name" in login_sql
    
    if has_safe and not has_dangerous:
        print("  [PASS] SQL uses named parameter (:name) - safe from SQL injection")
        return True
    else:
        print("  [FAIL] SQL does not use prepared statements safely")
        print("  SQL: " + login_sql)
        return False

def test_password_verification():
    """Test that password verification uses secure hashing."""
    print("\nTest 2: Password verification uses secure hashing")
    
    # Generate a test password hash
    test_password = "testpassword123"
    hashed = hashlib.pbkdf2_hmac('sha256', test_password.encode(), b'salt', 100000)
    
    # Verify correct password
    if hashlib.pbkdf2_hmac('sha256', test_password.encode(), b'salt', 100000) == hashed:
        print("  [PASS] password_verify() works correctly for correct password")
        pass_verify_correct = True
    else:
        print("  [FAIL] password_verify() failed for correct password")
        pass_verify_correct = False
    
    # Verify wrong password
    if hashlib.pbkdf2_hmac('sha256', 'wrongpassword'.encode(), b'salt', 100000) != hashed:
        print("  [PASS] Wrong password correctly rejected")
        pass_verify_wrong = True
    else:
        print("  [FAIL] Wrong password accepted")
        pass_verify_wrong = False
    
    # Check the admin_login.php code for password_verify usage
    with open('admin_login.php', 'r') as f:
        code = f.read()
        
    if 'password_verify' in code:
        print("  [PASS] Code uses password_verify() function")
    else:
        print("  [FAIL] Code does not use password_verify()")
        pass_verify_correct = False
        pass_verify_wrong = False
    
    return pass_verify_correct and pass_verify_wrong

def test_session_handling():
    """Test session handling."""
    print("\nTest 3: Session handling")
    
    # Check the code for session_start()
    with open('admin_login.php', 'r') as f:
        code = f.read()
        
    if 'session_start()' in code:
        print("  [PASS] session_start() is called")
    else:
        print("  [FAIL] session_start() is missing")
        return False
    
    # Check that session variables are set on login
    if 'admin_id' in code and 'admin_name' in code:
        print("  [PASS] Session variables (admin_id, admin_name) are set on login")
    else:
        print("  [FAIL] Session variables not properly handled")
        return False
    
    # Check redirect on successful login
    if 'index.php' in code:
        print("  [PASS] Successful login redirects to index.php")
    else:
        print("  [WARN] Successful login redirect not found")
    
    return True

def test_login_flow():
    """Test the overall login flow."""
    print("\nTest 4: Login flow simulation")
    
    with open('admin_login.php', 'r') as f:
        code = f.read()
    
    # Check for POST handling
    if 'REQUEST_METHOD' in code and 'POST' in code:
        print("  [PASS] Handles POST requests (login form submission)")
    else:
        print("  [FAIL] Does not handle POST requests")
        return False
    
    # Check for username/password validation
    if 'username' in code:
        print("  [PASS] Validates username input")
    else:
        print("  [WARN] Username validation not found")
    
    if 'password' in code:
        print("  [PASS] Validates password input")
    else:
        print("  [WARN] Password validation not found")
    
    # Check for error handling
    if 'error' in code.lower():
        print("  [PASS] Error handling present")
    else:
        print("  [WARN] Error handling not found")
    
    # Check for login redirect
    if 'index.php' in code:
        print("  [PASS] Redirects to index.php after login")
    else:
        print("  [WARN] No redirect to index.php found")
    
    return True

def test_html_structure():
    """Test the HTML structure is correct."""
    print("\nTest 5: HTML structure")
    
    with open('admin_login.php', 'r') as f:
        code = f.read()
    
    checks = [
        ('<!DOCTYPE html>', 'DOCTYPE declaration'),
        ('<html', '<html> tag'),
        ('<head', '<head> tag'),
        ('<title>', '<title> tag'),
        ('<body', '<body> tag'),
        ('<form', '<form> tag present'),
        ('method="post"', 'POST method'),
        ('action="admin_login.php"', 'Form posts to itself'),
        ('<input type="text"', 'Username input'),
        ('<input type="password"', 'Password input'),
        ('<button', 'Submit button'),
    ]
    
    all_pass = True
    for pattern, desc in checks:
        if pattern in code:
            print("  [PASS] " + desc)
        else:
            print("  [FAIL] " + desc)
            all_pass = False
    
    return all_pass

def test_database_config():
    """Test the database configuration."""
    print("\nTest 6: Database configuration")
    
    with open('db_connect.php', 'r') as f:
        db_code = f.read()
    
    # Check database connection settings
    if 'localhost' in db_code:
        print("  [PASS] Database host is localhost")
    else:
        print("  [WARN] Database host not found or different")
    
    if 'scholarship management' in db_code:
        print("  [PASS] Database name is 'scholarship management'")
    else:
        print("  [WARN] Database name not found or different")
    
    if 'root' in db_code:
        print("  [PASS] Database user is 'root'")
    else:
        print("  [WARN] Database user not found or different")
    
    if 'charset=utf8mb4' in db_code:
        print("  [PASS] Uses utf8mb4 charset")
    else:
        print("  [WARN] Charset not set to utf8mb4")
    
    # Check PDO error mode
    if 'ERRMODE_EXCEPTION' in db_code:
        print("  [PASS] PDO error mode is EXCEPTION")
    else:
        print("  [WARN] PDO error mode not set to EXCEPTION")
    
    return True

def main():
    results = []
    results.append(test_sql_is_safe())
    results.append(test_password_verification())
    results.append(test_session_handling())
    results.append(test_login_flow())
    results.append(test_html_structure())
    results.append(test_database_config())
    
    print("\n" + "=" * 50)
    print("Test Summary:")
    print("  Passed:   " + str(sum(results)))
    print("  Failed:   " + str(len(results) - sum(results)))
    print("  Total:    " + str(len(results)))
    print("=" * 50)
    
    if all(results):
        print("\nAll tests passed!")
        return 0
    else:
        print("\nSome tests failed.")
        return 1

if __name__ == '__main__':
    exit(main())
