from flask import Flask, request, jsonify
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

app = Flask(__name__)
CORS(app)

UPLOAD_FOLDER = 'uploads'
MODELS_FOLDER = 'models'
ALLOWED_EXTENSIONS = {'csv'}

os.makedirs(UPLOAD_FOLDER, exist_ok=True)
os.makedirs(MODELS_FOLDER, exist_ok=True)

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

@app.route('/train', methods=['POST'])
def train_model():
    """
    Train AI model from uploaded CSV file with enhanced model selection and hyperparameter tuning
    """
    try:
        if 'file' not in request.files:
            return jsonify({'success': False, 'error': 'No file uploaded'}), 400
        
        file = request.files['file']
        
        if file.filename == '':
            return jsonify({'success': False, 'error': 'No file selected'}), 400
        
        if not allowed_file(file.filename):
            return jsonify({'success': False, 'error': 'Invalid file type. Only CSV files are allowed'}), 400
        
        filename = secure_filename(file.filename)
        timestamp = datetime.datetime.now().strftime("%Y%m%d_%H%M%S")
        uploaded_filename = f"{timestamp}_{filename}"
        filepath = os.path.join(UPLOAD_FOLDER, uploaded_filename)
        file.save(filepath)
        
        data_set = pd.read_csv(filepath)
        
        if data_set.empty:
            return jsonify({'success': False, 'error': 'CSV file is empty'}), 400
        
        print(f"Dataset loaded - Shape: {data_set.shape}")
        print(f"Columns: {data_set.columns.tolist()}")
        
        data_set = preprocess_data(data_set)
        
        target_column = detect_target_column(data_set)
        print(f"Target column detected: {target_column}")
        
        if target_column not in data_set.columns:
            return jsonify({'success': False, 'error': f'Target column "{target_column}" not found'}), 400
        
        X = data_set.drop(target_column, axis=1)
        y = data_set[target_column]
        
        print(f"Features shape: {X.shape}")
        print(f"Target shape: {y.shape}")
        print(f"Target values: {y.value_counts().to_dict()}")
        
        class_counts = y.value_counts()
        min_class_count = class_counts.min()
        max_class_count = class_counts.max()
        imbalance_ratio = max_class_count / min_class_count if min_class_count > 0 else 1
        print(f"Class imbalance ratio: {imbalance_ratio:.2f}")
        
        target_encoder = None
        if y.dtype == 'object' or pd.api.types.is_categorical_dtype(y):
            le = LabelEncoder()
            y = le.fit_transform(y)
            target_encoder = le
            print(f"Target encoded: {dict(zip(le.classes_, range(len(le.classes_))))}")
        
        if len(np.unique(y)) < 2:
            return jsonify({'success': False, 'error': 'Target has less than 2 classes'}), 400
        
        scaler = StandardScaler()
        X_scaled = scaler.fit_transform(X)
        
        X_train, X_test, y_train, y_test = train_test_split(
            X_scaled, y, 
            test_size=0.2, 
            random_state=42, 
            stratify=y  
        )
        
        print(f"Training set: {X_train.shape}")
        print(f"Test set: {X_test.shape}")
        
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
        
        print("Evaluating different models with GridSearchCV...")
        for name, config in models_and_params.items():
            try:
                print(f"Running GridSearchCV for {name}...")
                grid_search = GridSearchCV(
                    config['model'],
                    config['params'],
                    cv=5,
                    scoring='accuracy',
                    n_jobs=-1,
                    verbose=1
                )
                grid_search.fit(X_train, y_train)
                
                mean_score = grid_search.best_score_
                model_scores[name] = mean_score
                print(f"{name}: Best CV Accuracy = {mean_score:.3f} with params: {grid_search.best_params_}")
                
                if mean_score > best_score:
                    best_score = mean_score
                    best_model = grid_search.best_estimator_
                    best_model_name = name
            except Exception as e:
                print(f"Error with {name} during GridSearchCV: {e}")
                print(f"Traceback for {name}: {traceback.format_exc()}")
                continue
        
        if best_model is None:
            best_model = RandomForestClassifier(n_estimators=100, random_state=42, max_depth=10)
            best_model_name = 'Random Forest (Fallback)'
            print("Using fallback Random Forest model as no better model was found or all failed.")
            best_model.fit(X_train, y_train)
            train_accuracy = accuracy_score(y_train, best_model.predict(X_train))
            test_accuracy = accuracy_score(y_test, best_model.predict(X_test))
            best_score = cross_val_score(best_model, X_train, y_train, cv=5, scoring='accuracy').mean()
            model_scores[best_model_name] = best_score 
        else:
            print(f"Selected model: {best_model_name}")
            train_accuracy = accuracy_score(y_train, best_model.predict(X_train))
            test_accuracy = accuracy_score(y_test, best_model.predict(X_test))
        
        print(f"Final Training Accuracy: {train_accuracy:.3f}")
        print(f"Final Testing Accuracy: {test_accuracy:.3f}")
        
        model_filename = f"mental_health_model_{timestamp}.joblib"
        model_path = os.path.join(MODELS_FOLDER, model_filename)
        
        model_data = {
            'model': best_model,
            'scaler': scaler,
            'target_encoder': target_encoder,
            'feature_names': X.columns.tolist(),
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
        
        os.remove(filepath)
        
        return jsonify({
            'success': True,
            'message': f'Mental Health Model trained successfully using {best_model_name}',
            'model_filename': model_filename,
            'train_accuracy': float(train_accuracy),
            'test_accuracy': float(test_accuracy),
            'cross_validation_score': float(best_score),
            'data_info': {
                'total_rows': int(data_set.shape[0]),
                'total_features': int(len(X.columns)),
                'target_column': target_column,
                'unique_classes': int(len(np.unique(y))),
                'class_distribution': {str(int(k)): int(v) for k, v in zip(*np.unique(y, return_counts=True))},
                'model_type': best_model_name,
                'class_imbalance_ratio': float(imbalance_ratio),
                'model_comparison': {k: float(v) for k, v in model_scores.items()} 
            },
            'training_timestamp': timestamp
        })
        
    except Exception as e:
        error_msg = str(e)
        print(f"Training failed: {error_msg}")
        print(f"Traceback: {traceback.format_exc()}")
        
        return jsonify({
            'success': False,
            'error': f'Training failed: {error_msg}'
        }), 500

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
        
        feature_names = model_data.get('feature_names', [])
        print(f"Model expects features: {feature_names}")
        
        feature_values = []
        missing_features = [f for f in feature_names if f not in data]
        if missing_features:
            return jsonify({
                'success': False,
                'error': f"Missing required features in input data: {', '.join(missing_features)}. Please provide all features: {', '.join(feature_names)}"
            }), 400

        for feature in feature_names:
            feature_values.append(data[feature])
            
        # Convert to DataFrame with proper feature names to avoid the warning
        X_new = pd.DataFrame([feature_values], columns=feature_names)

        print(f"Input for prediction (raw): {X_new.values}")

        scaler = model_data.get('scaler')
        if scaler is not None:
            X_new_scaled = scaler.transform(X_new)  # Now using DataFrame with feature names
            print(f"Scaled input: {X_new_scaled}")
        else:
            X_new_scaled = X_new.values
        
        model = model_data['model']
        prediction = model.predict(X_new_scaled)

        probabilities = np.array([])
        try:
            if hasattr(model, 'predict_proba'):
                probabilities = model.predict_proba(X_new_scaled)[0]
                prob_dict = {str(i): float(prob) for i, prob in enumerate(probabilities)} 
            else:
                prob_dict = {}
                print("Model does not support predict_proba.")
        except Exception as prob_e:
            prob_dict = {}
            print(f"Error getting probabilities: {prob_e}")
        
        print(f"Prediction result (encoded): {prediction}")
        
        target_encoder = model_data.get('target_encoder')
        if target_encoder is not None:
            predicted_label = target_encoder.inverse_transform(prediction)[0]
            if probabilities.size > 0 and hasattr(target_encoder, 'classes_'):
                prob_dict = {str(target_encoder.classes_[i]): float(prob) for i, prob in enumerate(probabilities)}
            print(f"Decoded prediction: {predicted_label}")
        else:
            predicted_label = prediction[0]
            if probabilities.size > 0:
                prob_dict = {str(i): float(prob) for i, prob in enumerate(probabilities)}

        confidence = float(np.max(probabilities)) if probabilities.size > 0 else 0.0
        
        return jsonify({
            'success': True,
            'prediction_encoded': int(prediction[0]), 
            'predicted_label': str(predicted_label),
            'probabilities': prob_dict,
            'confidence': confidence,
            'model_used': latest_model_filename,
            'input_processed': X_new.tolist()
        })
        
    except Exception as e:
        print(f"Prediction error: {str(e)}")
        print(f"Traceback: {traceback.format_exc()}")
        return jsonify({'success': False, 'error': str(e)}), 500

if __name__ == '__main__':
    print("Starting Youth Mental Health AI API...")
    print("Available endpoints:")
    print("  GET  /health        - Health check")
    print("  POST /train         - Train new model from CSV")
    print("  GET  /models        - List trained models and active model info")
    print("  POST /predict       - Make predictions using the latest trained model")
    
    log = logging.getLogger('werkzeug')
    log.setLevel(logging.ERROR)
    app.run(host='0.0.0.0', port=5000, debug=True)

