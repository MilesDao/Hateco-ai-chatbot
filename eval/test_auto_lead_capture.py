import re

def extract_vietnamese_name(message, captured_phone):
    phone_escaped = re.escape(captured_phone)
    
    # 1. Remove phone number and any associated phone labels (e.g. "số điện thoại của mình là", "sđt là", "số đt", "sdt", "liên hệ qua số")
    # Matches "số đt", "số điện thoại", "sđt", "sdt", "số" + optional possessives + optional "là" or punctuation + phone number + optional trailing particles
    phone_label_regex = r'(?:(?:[,\-\s:;]\s*)|^)(?:(?:s[\s\.\-_]*đ[\s\.\-_]*t|s[\s\.\-_]*d[\s\.\-_]*t|số\s*(?:đ|điện)?\s*(?:t|thoại)?|liên\s*hệ|cung\s*cấp)(?:\s+(?:của|qua|tại)?\s*(?:mình|em|anh|chị|tớ|bạn)?)?\s*(?:là|:|—|-|\s)*)*' + phone_escaped + r'(?:(?:\s+(?:ạ|nhá|nhé|nha|ah|đấy|nhỉ|nhắc)(?:\s+(?:ạ|ad|admin|anh|chị|em|bạn))?)+|(?=[,\-\.\s:;]|$))'
    
    clean = re.sub(phone_label_regex, ' ', message, flags=re.IGNORECASE)
    clean = clean.replace(captured_phone, ' ')
    
    # 2. Trim common leading polite words, leftover conversational particles, and symbols (Dạ, Vâng, Thưa, ạ, ơi, -, ,, :, ...)
    leading_polite_regex = r'^\s*(?:dạ|vâng|thưa|xin\s+cháo|xin\s+chào|ơi|à|ờ|ừ|ạ|nhé|nha|nhá|[,\-:;—_\.\/]+)+\s*'
    while re.match(leading_polite_regex, clean, flags=re.IGNORECASE):
        clean = re.sub(leading_polite_regex, '', clean, flags=re.IGNORECASE)
        
    # 3. Trim common trailing conversational particles & symbols
    trailing_polite_regex = r'\s+(?:nhé\s*(?:ạ|ad|admin|anh|chị|em|bạn)?|nha\s*(?:ạ|ad|admin|anh|chị|em|bạn)?|nhá\s*(?:ạ|ad|admin|anh|chị|em|bạn)?|nhỉ|đây\s+ạ|nhé|nha|nhá|ạ|ah|a|nhở|với\s+ạ|với|luôn\s+nhé|luôn)[,\-\s:;—_\.\!]*$'
    while re.search(trailing_polite_regex, clean, flags=re.IGNORECASE):
        clean = re.sub(trailing_polite_regex, '', clean, flags=re.IGNORECASE)
    
    # Trim lingering symbols at start and end
    clean = re.sub(r'^[,\-:;—_\.\/\s]+|[,\-:;—_\.\/\s]+$', '', clean)
    
    # 4. Check if what remains starts with an introduction pattern (mình tên là, em là, tên là, tên, họ tên...)
    intro_regex = r'^(?:(?:tên\s+)?(?:của\s+)?(?:mình|em|tớ|anh|chị|bạn|học\s*sinh)(?:\s+tên)?\s*(?:là|:|\s)|(?:mình|em|tớ|anh|chị|bạn)\s+tên\s*(?:là|:)?|tên\s*(?:của\s*)?(?:mình|em|tớ|anh|chị|bạn)?\s*(?:là|:|\s)|họ\s*(?:và)?\s*tên\s*(?:là|:)?|là\s+)\s*'
    
    match = re.match(intro_regex, clean, flags=re.IGNORECASE)
    if match:
        clean = clean[match.end():].strip()
        
    # One last pass to remove any residual trailing particles or symbols from the extracted name itself
    while re.search(trailing_polite_regex, clean, flags=re.IGNORECASE):
        clean = re.sub(trailing_polite_regex, '', clean, flags=re.IGNORECASE)
        
    clean = re.sub(r'\s+', ' ', clean).strip()
    clean = re.sub(r'^[,\-:;—_\.\/\s]+|[,\-:;—_\.\/\s]+$', '', clean)
    
    if len(clean) < 2:
        return "Học sinh (Qua Chat)"
    return clean

def test_lead_extraction(message, history):
    phone_regex = r'(?:(?:\+|00)84|0)(?:3|5|7|8|9)\d{8}\b'
    match = re.search(phone_regex, message)
    if not match:
        return {"captured": False}
    
    captured_phone = match.group(0)
    captured_name = extract_vietnamese_name(message, captured_phone)
    
    context_note = "Quan tâm chung (Để lại SĐT trong trò chuyện)"
    if history:
        for h_msg in reversed(history):
            if h_msg["role"] == "user" and captured_phone not in h_msg["content"]:
                context_note = f'Quan tâm: {h_msg["content"][:120]}'
                break
                
    return {
        "captured": True,
        "name": captured_name,
        "phone": captured_phone,
        "note": context_note
    }

if __name__ == "__main__":
    test_cases = [
        # User's exact reported bug case
        {"msg": "Dạ mình tên là Trung nhé số điện thoại là 0985282393", "expected_name": "Trung"},
        
        # Variations with phone first, name later, different particles and introduction patterns
        {"msg": "Mình là Nguyễn Hưng 0987654321 nhé", "expected_name": "Nguyễn Hưng"},
        {"msg": "0912345678, Trần Lan Phương", "expected_name": "Trần Lan Phương"},
        {"msg": "Em là Long, số em 0987654321 ạ", "expected_name": "Long"},
        {"msg": "0355444333", "expected_name": "Học sinh (Qua Chat)"},
        {"msg": "Tên em là Lê Văn Luyện 0988776655 nha chị", "expected_name": "Lê Văn Luyện"},
        {"msg": "Nguyễn Thu Trà - 0911223344", "expected_name": "Nguyễn Thu Trà"},
        {"msg": "sđt mình là 0985282393 tên Trung nhé", "expected_name": "Trung"},
        {"msg": "Dạ 0985282393 mình tên Trung nhé ạ", "expected_name": "Trung"},
        {"msg": "Vâng sđt của em là 0985282393 ạ, em là Ngọc Đào nhé ad", "expected_name": "Ngọc Đào"},
        {"msg": "chị tên là Bích Vân, số đt 0988888888", "expected_name": "Bích Vân"},
    ]
    
    print("=" * 70)
    print("VIETNAMESE LEADS NAME EXTRACTION BENCHMARK")
    print("=" * 70)
    passed = 0
    for i, tc in enumerate(test_cases, 1):
        res = test_lead_extraction(tc["msg"], [])
        actual_name = res.get("name")
        is_correct = (actual_name == tc["expected_name"])
        status = "✅ PASS" if is_correct else "❌ FAIL"
        if is_correct:
            passed += 1
        print(f"[{status}] Case {i}: \"{tc['msg']}\"")
        print(f"      ► Expected: [{tc['expected_name']}] | Actual: [{actual_name}]")
        print("-" * 70)
    print(f"Summary: {passed}/{len(test_cases)} Passed ({passed/len(test_cases)*100:.1f}%)")
