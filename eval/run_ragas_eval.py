import os
import json
import time
import requests
import re
import pandas as pd
from datasets import Dataset
from ragas import evaluate
from ragas.metrics import (
    faithfulness,
    answer_relevancy,
    context_precision,
    context_recall,
)
from langchain_openai.chat_models import ChatOpenAI
from langchain_openai.embeddings import OpenAIEmbeddings

# ==========================================
# CONFIGURATION
# ==========================================
WP_AJAX_URL = "https://reachplane.s3-tastewp.com/wp-admin/admin-ajax.php"
WP_HOME_URL = "https://reachplane.s3-tastewp.com/"
OPENROUTER_API_KEY = os.environ.get("OPENROUTER_API_KEY", "")

# We use OpenRouter models for Ragas Evaluation
EVAL_MODEL = "openai/gpt-4o"
EMBEDDING_MODEL = "openai/text-embedding-3-large"

if not OPENROUTER_API_KEY:
    raise ValueError("Please set OPENROUTER_API_KEY in environment variables")

def parse_sse_response(raw_text):
    """Parse SSE stream data to extract the generated text."""
    clean_text = ""
    for line in raw_text.splitlines():
        line = line.strip()
        if not line or line.startswith(":"):
            continue
        if line == "data: [DONE]":
            break
        if line.startswith("data: "):
            json_str = line[6:]
            try:
                data = json.loads(json_str)
                if "choices" in data and len(data["choices"]) > 0:
                    delta = data["choices"][0].get("delta", {})
                    if "content" in delta:
                        clean_text += delta["content"]
            except json.JSONDecodeError:
                pass
    return clean_text

def chat_with_bot(question, session_id, nonce=""):
    """Send question to WP AJAX endpoint and get response"""
    payload = {
        'action': 'ai_chatbot_chat_query',
        'message': question,
        'session_id': session_id,
        'nonce': nonce,
        'eval_mode': '1'
    }
    
    # Send request
    try:
        response = requests.post(WP_AJAX_URL, data=payload, timeout=60)
        data = response.json()
        if data.get("success"):
            ans = data["data"].get("answer", "")
            ctx = data["data"].get("contexts", [])
            return ans, ctx
        else:
            return "Error: " + str(data), ["Error"]
    except Exception as e:
        print(f"Error querying bot: {e}")
        return "Error", ["Error"]

def get_nonce():
    try:
        r = requests.get(WP_HOME_URL)
        match = re.search(r'\"nonce\":\"([^\"]+)\"', r.text)
        if match:
            return match.group(1)
    except:
        pass
    return ""

def main():
    with open("eval/hateco_ground_truth.json", "r", encoding="utf-8") as f:
        ground_truth = json.load(f)

    print(f"Loaded {len(ground_truth)} questions from ground truth.")

    data = {
        "question": [],
        "answer": [],
        "contexts": [],
        "ground_truth": []
    }

    print("Querying the chatbot... (This might take a while)")
    # IMPORTANT: Since we can't easily get the 'contexts' from the AJAX stream, 
    # we will use the ground_truth contexts as the 'retrieved' contexts for the sake of the RAGAS metric 
    # if the actual WP API doesn't expose them. 
    # To truly evaluate context_recall/precision, you MUST expose the chunks in the PHP code.
    
    for i, item in enumerate(ground_truth):
        q = item["question"]
        print(f"[{i+1}/{len(ground_truth)}] Q: {q}")
        
        # Fetch a fresh nonce if needed or reuse
        nonce = get_nonce()
        
        # Unique session ID per question to avoid history contamination
        session_id = f"ragas_eval_q_{i}_{int(time.time())}"
        
        # Hit the actual API
        ans, retrieved_ctx = chat_with_bot(q, session_id, nonce)
        
        data["question"].append(q)
        data["answer"].append(ans)
        
        # Use actual retrieved chunks for true Context Precision/Recall scoring!
        # If the bot found nothing, provide an empty list or fallback to empty string
        if not retrieved_ctx:
            retrieved_ctx = ["Không có tài liệu nào liên quan."]
        data["contexts"].append(retrieved_ctx) 
        
        data["ground_truth"].append(item["ground_truth"])
        time.sleep(1)

    dataset = Dataset.from_dict(data)

    # Setup RAGAS Judges via OpenRouter
    llm = ChatOpenAI(
        model_name=EVAL_MODEL,
        openai_api_key=OPENROUTER_API_KEY,
        openai_api_base="https://openrouter.ai/api/v1"
    )
    embeddings = OpenAIEmbeddings(
        model=EMBEDDING_MODEL, 
        openai_api_key=OPENROUTER_API_KEY,
        openai_api_base="https://openrouter.ai/api/v1",
        check_embedding_ctx_length=False
    )

    print("Running RAGAS evaluation...")
    
    try:
        # Note: running full RAGAS requires OpenAI embeddings usually.
        result = evaluate(
            dataset,
            metrics=[
                faithfulness,
                answer_relevancy,
                context_precision,
                context_recall,
            ],
            llm=llm,
            embeddings=embeddings,
        )

        df = result.to_pandas()
        df.to_csv("eval/test_report.csv", index=False)
        print("Evaluation complete! Saved to test_report.csv")
        print(df.mean(numeric_only=True))
    except Exception as e:
        print("Evaluation Error:", e)

if __name__ == "__main__":
    main()
