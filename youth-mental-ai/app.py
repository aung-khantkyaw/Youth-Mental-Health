from flask import Flask, request, Response, jsonify
from flask_cors import CORS
import pandas as pd
import numpy as np
from sklearn.model_selection import train_test_split, cross_val_score, GridSearchCV
from sklearn.naive_bayes import GaussianNB
from sklearn.preprocessing import LabelEncoder, StandardScaler
from sklearn.metrics import accuracy_score
from sklearn.ensemble import RandomForestClassifier
from sklearn.linear_model import LogisticRegression
from sklearn.svm import SVC 
from xgboost import XGBClassifier
import joblib
import os
import datetime
import traceback
from werkzeug.utils import secure_filename
import logging
import json
import sys
import io
from contextlib import contextmanager

app = Flask(__name__)
CORS(app)

UPLOAD_FOLDER = 'uploads'
MODELS_FOLDER = 'models'
ALLOWED_EXTENSIONS = {'csv'}

os.makedirs(UPLOAD_FOLDER, exist_ok=True)
os.makedirs(MODELS_FOLDER, exist_ok=True)

class LogCapture:
    def __init__(self):
        self.logs = []
        self.original_stdout = sys.stdout
        self.original_stderr = sys.stderr

    def write(self, text):
        if text.strip():
            self.logs.append(text.strip())
        self.original_stdout.write(text)

    def flush(self):
        self.original_stdout.flush()
        self.original_stderr.flush()

def allowed_file(filename):
    return '.' in filename and filename.rsplit('.', 1)[1].lower() in ALLOWED_EXTENSIONS

def preprocess_data(df):
    """
    Enhanced preprocessing for better model accuracy
    """
    try:
        print(f"Starting preprocessing with shape: {df.shape}")
        print(f"Columns: {df.columns.tolist()}")
        
        df = df.dropna(how='all')
        print(f"After removing empty rows: {df.shape}")
        
        initial_shape = df.shape[0]
        df = df.drop_duplicates()
        print(f"Removed {initial_shape - df.shape[0]} duplicate rows")
        
        numeric_cols = df.select_dtypes(include=[np.number]).columns
        for col in numeric_cols:
            if df[col].isnull().any():
                df[col].fillna(df[col].median(), inplace=True)
        
        categorical_cols = df.select_dtypes(include=['object']).columns
        for col in categorical_cols:
            if df[col].isnull().any():
                df[col].fillna(df[col].mode()[0] if not df[col].mode().empty else 'Unknown', inplace=True)
        
        for col in numeric_cols:
            if col.lower() != 'mood' and col in df.columns:
                Q1 = df[col].quantile(0.25)
                Q3 = df[col].quantile(0.75)
                IQR = Q3 - Q1
                lower_bound = Q1 - 1.5 * IQR
                upper_bound = Q3 + 1.5 * IQR
                
                outliers_before = ((df[col] < lower_bound) | (df[col] > upper_bound)).sum()
                df[col] = df[col].clip(lower=lower_bound, upper=upper_bound)
                if outliers_before > 0:
                    print(f"Capped {outliers_before} outliers in {col}")
        
        print(f"Final preprocessing shape: {df.shape}")
        print(f"Final columns: {df.columns.tolist()}")
        
        return df
        
    except Exception as e:
        print(f"Preprocessing error: {str(e)}")
        print(f"Full traceback: {traceback.format_exc()}")
        raise Exception(f"Error in preprocessing: {str(e)}")

def detect_target_column(df):
    """
    Simple target column detection - prioritize 'Mood' like in the notebook
    """
    print(f"Detecting target from columns: {df.columns.tolist()}")
    
    priority_targets = ['mood', 'mental_health', 'depression', 'anxiety', 'stress']
    
    for col in df.columns:
        col_lower = str(col).lower()
        for target in priority_targets:
            if target in col_lower:
                print(f"Found priority target: {col}")
                return col
    
    last_col = df.columns[-1]
    print(f"No priority target found, using last column: {last_col}")
    return last_col

@app.route('/health', methods=['GET'])
def health_check():
    """Health check endpoint"""
    return jsonify({
        'status': 'healthy',
        'message': 'Youth Mental Health AI API is running',
        'timestamp': datetime.datetime.now().isoformat()
    })

@app.route('/train-stream', methods=['POST'])
def train_model_stream():
    """Train model with real-time log streaming"""
    
    try:
        if 'file' not in request.files:
            return Response(f"data: {json.dumps({'error': 'No file uploaded'})}\n\n", 
                          mimetype='text/event-stream')
        
        file = request.files['file']
        
        if file.filename == '':
            return Response(f"data: {json.dumps({'error': 'No file selected'})}\n\n", 
                          mimetype='text/event-stream')
        
        if not allowed_file(file.filename):
            return Response(f"data: {json.dumps({'error': 'Invalid file type. Only CSV files are allowed'})}\n\n", 
                          mimetype='text/event-stream')
        
        filename = secure_filename(file.filename)
        timestamp = datetime.datetime.now().strftime("%Y%m%d_%H%M%S")
        uploaded_filename = f"{timestamp}_{filename}"
        filepath = os.path.join(UPLOAD_FOLDER, uploaded_filename)
        file.save(filepath)
        
    except Exception as e:
        return Response(f"data: {json.dumps({'error': f'Initial setup failed: {str(e)}'})}\n\n", 
                      mimetype='text/event-stream')
    
    def generate_logs():
        try:
            yield f"data: {json.dumps({'log': 'Starting training process...', 'type': 'info'})}\n\n"
            yield f"data: {json.dumps({'log': f'File saved: {uploaded_filename}', 'type': 'success'})}\n\n"
            
            data_set = pd.read_csv(filepath)
            
            if data_set.empty:
                yield f"data: {json.dumps({'error': 'CSV file is empty'})}\n\n"
                return
                
            yield f"data: {json.dumps({'log': f'Dataset loaded - Shape: {data_set.shape}', 'type': 'info'})}\n\n"
            yield f"data: {json.dumps({'log': f'Columns: {data_set.columns.tolist()}', 'type': 'info'})}\n\n"
            
            yield f"data: {json.dumps({'log': 'Starting data preprocessing...', 'type': 'info'})}\n\n"
            data_set = preprocess_data(data_set)
            yield f"data: {json.dumps({'log': f'Preprocessing completed - Final shape: {data_set.shape}', 'type': 'success'})}\n\n"
            
            target_column = detect_target_column(data_set)
            yield f"data: {json.dumps({'log': f'Target column detected: {target_column}', 'type': 'info'})}\n\n"
            
            if target_column not in data_set.columns:
                yield f"data: {json.dumps({'error': f'Target column \"{target_column}\" not found'})}\n\n"
                return
            
            X = data_set.drop(target_column, axis=1)
            y = data_set[target_column]
            
            yield f"data: {json.dumps({'log': f'Features shape: {X.shape}', 'type': 'info'})}\n\n"
            yield f"data: {json.dumps({'log': f'Target shape: {y.shape}', 'type': 'info'})}\n\n"
            yield f"data: {json.dumps({'log': f'Target values: {y.value_counts().to_dict()}', 'type': 'info'})}\n\n"
            
            target_encoder = None
            if y.dtype == 'object' or pd.api.types.is_categorical_dtype(y):
                le = LabelEncoder()
                y = le.fit_transform(y)
                target_encoder = le
                yield f"data: {json.dumps({'log': f'Target encoded: {dict(zip(le.classes_, range(len(le.classes_))))}', 'type': 'info'})}\n\n"
            
            if len(np.unique(y)) < 2:
                yield f"data: {json.dumps({'error': 'Target has less than 2 classes'})}\n\n"
                return
            
            yield f"data: {json.dumps({'log': 'Scaling features...', 'type': 'info'})}\n\n"
            scaler = StandardScaler()
            X_scaled = scaler.fit_transform(X)
            
            X_train, X_test, y_train, y_test = train_test_split(
                X_scaled, y, 
                test_size=0.2, 
                random_state=42, 
                stratify=y  
            )
            
            yield f"data: {json.dumps({'log': f'Training set: {X_train.shape}', 'type': 'info'})}\n\n"
            yield f"data: {json.dumps({'log': f'Test set: {X_test.shape}', 'type': 'info'})}\n\n"
            
            models_and_params = {
                'Random Forest': {
                    'model': RandomForestClassifier(random_state=42),
                    'params': {
                        'n_estimators': [50, 100, 200],
                        'max_depth': [5, 10, 15, None],
                        'min_samples_split': [2, 5, 10]
                    }
                },
                'Logistic Regression': {
                    'model': LogisticRegression(random_state=42, max_iter=2000),
                    'params': {
                        'C': [0.01, 0.1, 1.0, 10.0],
                        'solver': ['liblinear', 'lbfgs'] 
                    }
                },
                'Gaussian Naive Bayes': {
                    'model': GaussianNB(),
                    'params': {} 
                },
                'Support Vector Machine': {
                    'model': SVC(random_state=42, probability=True),
                    'params': {
                        'C': [0.1, 1.0, 10.0],
                        'kernel': ['linear', 'rbf']
                    }
                },
                'XGBoost': { 
                    'model': XGBClassifier(random_state=42, eval_metric='mlogloss'),
                    'params': {
                        'n_estimators': [50, 100, 200],
                        'learning_rate': [0.01, 0.1, 0.2],
                        'max_depth': [3, 5, 7]
                    }
                }
            }
            
            best_model = None
            best_score = 0
            best_model_name = ''
            model_scores = {}
            
            yield f"data: {json.dumps({'log': 'Evaluating different models with GridSearchCV...', 'type': 'info'})}\n\n"
            
            for name, config in models_and_params.items():
                try:
                    yield f"data: {json.dumps({'log': f'Running GridSearchCV for {name}...', 'type': 'info'})}\n\n"
                    grid_search = GridSearchCV(
                        config['model'],
                        config['params'],
                        cv=5,
                        scoring='accuracy',
                        n_jobs=-1,
                        verbose=0 
                    )
                    grid_search.fit(X_train, y_train)
                    
                    mean_score = grid_search.best_score_
                    model_scores[name] = mean_score
                    yield f"data: {json.dumps({'log': f'{name}: Best CV Accuracy = {mean_score:.3f} with params: {grid_search.best_params_}', 'type': 'success'})}\n\n"
                    
                    if mean_score > best_score:
                        best_score = mean_score
                        best_model = grid_search.best_estimator_
                        best_model_name = name
                        
                except Exception as e:
                    yield f"data: {json.dumps({'log': f'Error with {name} during GridSearchCV: {e}', 'type': 'error'})}\n\n"
                    continue
            
            if best_model is None:
                best_model = RandomForestClassifier(n_estimators=100, random_state=42, max_depth=10)
                best_model_name = 'Random Forest (Fallback)'
                yield f"data: {json.dumps({'log': 'Using fallback Random Forest model as no better model was found or all failed.', 'type': 'warning'})}\n\n"
                best_model.fit(X_train, y_train)
                train_accuracy = accuracy_score(y_train, best_model.predict(X_train))
                test_accuracy = accuracy_score(y_test, best_model.predict(X_test))
                best_score = cross_val_score(best_model, X_train, y_train, cv=5, scoring='accuracy').mean()
                model_scores[best_model_name] = best_score 
            else:
                yield f"data: {json.dumps({'log': f'Selected model: {best_model_name}', 'type': 'success'})}\n\n"

                train_accuracy = accuracy_score(y_train, best_model.predict(X_train))
                test_accuracy = accuracy_score(y_test, best_model.predict(X_test))
            
            yield f"data: {json.dumps({'log': f'Final Training Accuracy: {train_accuracy:.3f}', 'type': 'success'})}\n\n"
            yield f"data: {json.dumps({'log': f'Final Testing Accuracy: {test_accuracy:.3f}', 'type': 'success'})}\n\n"
            
            yield f"data: {json.dumps({'log': 'Saving model...', 'type': 'info'})}\n\n"
            model_filename = f"mental_health_model_{timestamp}.joblib"
            model_path = os.path.join(MODELS_FOLDER, model_filename)
            
            class_counts = y.value_counts() if hasattr(y, 'value_counts') else pd.Series(y).value_counts()
            min_class_count = class_counts.min()
            max_class_count = class_counts.max()
            imbalance_ratio = max_class_count / min_class_count if min_class_count > 0 else 1
            
            model_data = {
                'model': best_model,
                'scaler': scaler,
                'target_encoder': target_encoder,
                'feature_names': list(X.columns),
                'target_column': target_column,
                'model_name': best_model_name,
                'train_accuracy': train_accuracy,
                'test_accuracy': test_accuracy,
                'cross_validation_score': best_score,
                'training_info': {
                    'timestamp': timestamp,
                    'original_filename': filename,
                    'data_shape': (int(data_set.shape[0]), int(data_set.shape[1])),
                    'features_count': int(len(X.columns)),
                    'classes': int(len(np.unique(y))),
                    'class_distribution': {str(int(k)): int(v) for k, v in zip(*np.unique(y, return_counts=True))},
                    'class_imbalance_ratio': float(imbalance_ratio),
                    'model_scores': {k: float(v) for k, v in model_scores.items()} 
                }
            } 
            
            joblib.dump(model_data, model_path)
            
            if os.path.exists(filepath):
                os.remove(filepath)
            
            yield f"data: {json.dumps({'log': f'Model saved successfully: {model_filename}', 'type': 'success'})}\n\n"
            
            yield f"data: {json.dumps({'success': True, 'message': f'Mental Health Model trained successfully using {best_model_name}', 'model_filename': model_filename, 'train_accuracy': float(train_accuracy), 'test_accuracy': float(test_accuracy), 'cross_validation_score': float(best_score)})}\n\n"
            
        except Exception as e:
            error_msg = str(e)
            import traceback
            traceback_str = traceback.format_exc()
            yield f"data: {json.dumps({'error': f'Training failed: {error_msg}', 'traceback': traceback_str, 'type': 'error'})}\n\n"
            
            if 'filepath' in locals() and os.path.exists(filepath):
                os.remove(filepath)
    
    return Response(generate_logs(), mimetype='text/event-stream', headers={
        'Cache-Control': 'no-cache',
        'Connection': 'keep-alive',
        'Access-Control-Allow-Origin': '*',
        'Access-Control-Allow-Headers': 'Cache-Control'
    })

@app.route('/models', methods=['GET'])
def list_models():
    """List all trained models and get active model info"""
    try:
        models = []
        latest_model_info = None  
        latest_timestamp_val = 0  

        if not os.path.exists(MODELS_FOLDER):
            return jsonify({
                'success': True,
                'models': [],
                'count': 0,
                'active_model': None,
                'message': 'No models folder found. Train a model first.'
            })
        
        for filename in os.listdir(MODELS_FOLDER):
            if filename.endswith('.joblib'):
                model_path = os.path.join(MODELS_FOLDER, filename)
                stat = os.stat(model_path)
                
                try:
                    model_data = joblib.load(model_path)
                    training_info = model_data.get('training_info', {})
                    train_accuracy = model_data.get('train_accuracy', 'Unknown')
                    test_accuracy = model_data.get('test_accuracy', 'Unknown')
                    target_column = model_data.get('target_column', 'Unknown')
                    feature_names = model_data.get('feature_names', [])
                    model_type = model_data.get('model_name', 'Unknown')
                    
                    model_info = {
                        'filename': filename,
                        'size': stat.st_size,
                        'created': datetime.datetime.fromtimestamp(stat.st_ctime).isoformat(),
                        'train_accuracy': train_accuracy,
                        'test_accuracy': test_accuracy,
                        'target_column': target_column,
                        'num_features': len(feature_names),
                        'feature_names': feature_names,
                        'model_type': model_type, 
                        'training_info': training_info
                    }
                    
                    if stat.st_ctime > latest_timestamp_val:
                        latest_timestamp_val = stat.st_ctime
                        latest_model_info = model_info
                        
                except Exception as load_error:
                    print(f"Error loading model {filename}: {load_error}")
                    model_info = {
                        'filename': filename,
                        'size': stat.st_size,
                        'created': datetime.datetime.fromtimestamp(stat.st_ctime).isoformat(),
                        'error': f'Failed to load: {str(load_error)}'
                    }
                
                models.append(model_info)
        
        models.sort(key=lambda x: x['created'], reverse=True)
        
        return jsonify({
            'success': True,
            'models': models,
            'count': len(models),
            'active_model': latest_model_info,
            'active_model_filename': latest_model_info['filename'] if latest_model_info else None
        })
        
    except Exception as e:
        print(f"Error listing models: {str(e)}")
        return jsonify({
            'success': False,
            'error': str(e)
        }), 500

@app.route('/predict', methods=['POST'])
def predict():
    """Make predictions using the latest trained model"""
    try:
        data = request.get_json()
        
        if not data:
            return jsonify({'success': False, 'error': 'No input data provided'}), 400
        
        if not os.path.exists(MODELS_FOLDER):
            return jsonify({'success': False, 'error': 'No models folder found. Please train a model first.'}), 404
        
        model_files = [f for f in os.listdir(MODELS_FOLDER) if f.endswith('.joblib')]
        if not model_files:
            return jsonify({'success': False, 'error': 'No trained models found. Please train a model first.'}), 404
        
        latest_model_filename = sorted(model_files)[-1]
        model_path = os.path.join(MODELS_FOLDER, latest_model_filename)
        
        print(f"Using latest model: {latest_model_filename}")
        
        model_data = joblib.load(model_path)
        
        model = model_data['model']
        scaler = model_data.get('scaler')
        target_encoder = model_data.get('target_encoder')
        feature_names = model_data.get('feature_names', [])
        
        if hasattr(feature_names, 'tolist'):
            feature_names = feature_names.tolist()
        elif not isinstance(feature_names, list):
            feature_names = list(feature_names)
        
        print(f"Model expects features: {feature_names}")
        
        for feature in feature_names:
            if feature not in data:
                return jsonify({
                    'success': False, 
                    'error': f'Missing required feature: {feature}'
                }), 400
        
        feature_values = []
        for feature in feature_names:
            feature_values.append(data[feature])
        
        X_new = pd.DataFrame([feature_values], columns=feature_names)
        
        print(f"Input for prediction (raw): {X_new.values}")
        
        if scaler is not None:
            X_new_scaled = scaler.transform(X_new)
            print(f"Scaled input: {X_new_scaled}")
        else:
            X_new_scaled = X_new.values
            print("No scaler found, using raw input")
        
        prediction = model.predict(X_new_scaled)
        print(f"Prediction result (encoded): {prediction}")
        
        try:
            prediction_proba = model.predict_proba(X_new_scaled)
            confidence = float(np.max(prediction_proba))
            print(f"Prediction probabilities: {prediction_proba}")
            print(f"Confidence: {confidence}")
        except Exception as e:
            print(f"Could not get prediction probabilities: {e}")
            confidence = None
            prediction_proba = None
        
        if target_encoder is not None:
            try:
                predicted_label = target_encoder.inverse_transform(prediction)[0]
                print(f"Decoded prediction: {predicted_label}")
            except Exception as e:
                print(f"Error decoding prediction: {e}")
                predicted_label = str(prediction[0])
        else:
            predicted_label = str(prediction[0])
            print(f"No target encoder, using raw prediction: {predicted_label}")
        
        response_data = {
            'success': True,
            'predicted_label': predicted_label,
            'prediction': int(prediction[0]) if isinstance(prediction[0], (int, np.integer)) else prediction[0],
            'confidence': confidence,
            'model_used': latest_model_filename,
            'features_used': feature_names
        }
        
        if prediction_proba is not None:
            response_data['probabilities'] = prediction_proba[0].tolist()
        
        print(f"Final response: {response_data}")
        
        return jsonify(response_data)
        
    except Exception as e:
        error_msg = str(e)
        print(f"Prediction error: {error_msg}")
        import traceback
        traceback.print_exc()
        
        return jsonify({
            'success': False,
            'error': error_msg
        }), 500

if __name__ == '__main__':
    print("Starting Youth Mental Health AI API...")
    print("Available endpoints:")
    print("  GET  /health        - Health check")
    print("  POST /train-stream  - Train new model with real-time log streaming")
    print("  GET  /models        - List trained models and active model info")
    print("  POST /predict       - Make predictions using the latest trained model")
    
    log = logging.getLogger('werkzeug')
    log.setLevel(logging.ERROR)
    app.run(host='0.0.0.0', port=5000, debug=True)

