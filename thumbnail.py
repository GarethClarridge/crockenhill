import cv2
import dlib
import numpy as np
from collections import defaultdict

def find_best_camera_gaze_frame(video_path, sample_interval=30):
    """
    Find the single frame where speaker looks most directly at camera
    sample_interval: process every Nth frame (30 = ~1 frame per second for 30fps video)
    """
    
    # Initialize face detection
    detector = dlib.get_frontal_face_detector()
    predictor = dlib.shape_predictor("shape_predictor_68_face_landmarks.dat")
    
    cap = cv2.VideoCapture(video_path)
    
    best_frame = None
    best_score = -1
    best_frame_number = -1
    frame_scores = []
    
    frame_count = 0
    
    while True:
        ret, frame = cap.read()
        if not ret:
            break
            
        # Only process every Nth frame for efficiency
        if frame_count % sample_interval == 0:
            score = calculate_gaze_score(frame, detector, predictor)
            
            if score > best_score:
                best_score = score
                best_frame = frame.copy()
                best_frame_number = frame_count
                
            frame_scores.append((frame_count, score))
            
        frame_count += 1
    
    cap.release()
    
    return best_frame, best_frame_number, best_score, frame_scores

def calculate_gaze_score(frame, detector, predictor):
    """
    Calculate how directly the person is looking at the camera
    Higher score = more direct gaze
    """
    gray = cv2.cvtColor(frame, cv2.COLOR_BGR2GRAY)
    faces = detector(gray)
    
    if len(faces) != 1:  # Should be exactly one face
        return 0
    
    face = faces[0]
    landmarks = predictor(gray, face)
    
    # Convert landmarks to coordinate pairs
    points = []
    for i in range(68):
        x = landmarks.part(i).x
        y = landmarks.part(i).y
        points.append((x, y))
    
    # Calculate multiple gaze indicators
    eye_symmetry_score = calculate_eye_symmetry(points)
    head_pose_score = calculate_head_pose(points)
    eye_openness_score = calculate_eye_openness(points)
    
    # Combine scores (adjust weights based on your needs)
    total_score = (eye_symmetry_score * 0.5 + 
                   head_pose_score * 0.3 + 
                   eye_openness_score * 0.2)
    
    return total_score

def calculate_eye_symmetry(landmarks):
    """
    Measure how symmetrically positioned the eyes are relative to nose
    Perfect symmetry indicates looking straight ahead
    """
    # Left eye center (average of corners)
    left_eye = [(landmarks[36][0] + landmarks[39][0]) // 2,
                (landmarks[36][1] + landmarks[39][1]) // 2]
    
    # Right eye center
    right_eye = [(landmarks[42][0] + landmarks[45][0]) // 2,
                 (landmarks[42][1] + landmarks[45][1]) // 2]
    
    # Nose tip
    nose = landmarks[30]
    
    # Calculate distances from nose to each eye
    left_distance = abs(left_eye[0] - nose[0])
    right_distance = abs(right_eye[0] - nose[0])
    
    # Symmetry ratio (closer to 1.0 = more symmetric)
    if max(left_distance, right_distance) == 0:
        return 0
    
    symmetry = min(left_distance, right_distance) / max(left_distance, right_distance)
    return symmetry

def calculate_head_pose(landmarks):
    """
    Estimate head pose based on facial landmarks
    Score higher for more frontal poses
    """
    # Use nose bridge and tip to estimate head angle
    nose_bridge = landmarks[27]  # Between eyebrows
    nose_tip = landmarks[30]
    chin = landmarks[8]
    
    # Calculate face centerline angle
    face_center_x = (landmarks[0][0] + landmarks[16][0]) // 2  # Jaw corners
    
    # Distance from nose tip to face center (should be small for frontal view)
    nose_center_distance = abs(nose_tip[0] - face_center_x)
    face_width = abs(landmarks[16][0] - landmarks[0][0])
    
    if face_width == 0:
        return 0
    
    # Normalize by face width
    pose_score = 1.0 - (nose_center_distance / (face_width * 0.5))
    return max(0, pose_score)

def calculate_eye_openness(landmarks):
    """
    Prefer frames where eyes are clearly open
    """
    # Left eye height
    left_eye_height = abs(landmarks[37][1] - landmarks[41][1])
    
    # Right eye height  
    right_eye_height = abs(landmarks[43][1] - landmarks[47][1])
    
    # Average eye height (normalized by face height)
    face_height = abs(landmarks[8][1] - landmarks[27][1])
    
    if face_height == 0:
        return 0
    
    avg_eye_height = (left_eye_height + right_eye_height) / 2
    eye_openness = avg_eye_height / (face_height * 0.1)  # Rough normalization
    
    return min(1.0, eye_openness)  # Cap at 1.0

# Usage
if __name__ == "__main__":
    video_path = "small.mp4"
    
    print("Analyzing video for best camera gaze frame...")
    best_frame, frame_number, score, all_scores = find_best_camera_gaze_frame(video_path)
    
    if best_frame is not None:
        # Save the best frame
        cv2.imwrite("best_camera_gaze_frame.jpg", best_frame)
        
        # Convert frame number to timestamp
        cap = cv2.VideoCapture(video_path)
        fps = cap.get(cv2.CAP_PROP_FPS)
        timestamp = frame_number / fps
        minutes = int(timestamp // 60)
        seconds = int(timestamp % 60)
        
        print(f"Best frame found at {minutes:02d}:{seconds:02d} (frame {frame_number})")
        print(f"Gaze score: {score:.3f}")
        
        # Optionally, show top 5 candidates
        sorted_scores = sorted(all_scores, key=lambda x: x[1], reverse=True)[:5]
        print("\nTop 5 candidates:")
        for frame_num, gaze_score in sorted_scores:
            timestamp = frame_num / fps
            mins = int(timestamp // 60)
            secs = int(timestamp % 60)
            print(f"  {mins:02d}:{secs:02d} - Score: {gaze_score:.3f}")
    else:
        print("No suitable frame found")